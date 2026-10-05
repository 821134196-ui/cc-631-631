# 连锁商户营业时间临时调整与渠道同步系统

门店临时停业 / 延长营业时，**只提交一次申请**，总部批准后自动把新时间同步到
**官网、店内屏、外卖平台** 三个渠道（本地模拟）。

技术栈：PHP 8.4 · Laravel 13 · React 19 · Eloquent · SQLite。

## 一条命令启动

```bash
./start.sh
```

脚本会自动：准备 PHP（无系统 PHP 时下载静态编译版到 `.tools/`）→ 安装依赖 →
迁移并填充 SQLite → 构建前端 → 同时启动 **Web 服务 + 队列 worker（异步回执）+
调度器（到期恢复）**。打开 http://localhost:8000 即可使用，`Ctrl+C` 停止全部进程。

运行测试：

```bash
export PATH="$PWD/.tools:$PATH"
php artisan test
```

## 页面

| 页面 | 说明 |
| --- | --- |
| 门店日历 | **当前实际对外生效**的营业时间；审批中的草稿以虚线角标单独提示，绝不当作已发布内容 |
| 提交调整 | 新申请；生效中的调整可**延期 / 提前结束**（生成新版本，原记录保留） |
| 总部审核 | 待审批队列；批准后显示各渠道首发结果 |
| 渠道状态 | 各渠道**当前实际显示**的内容 + 每次发布的回执日志；失败渠道**单独重试** |
| 调整记录 | 按组展示版本历史：批准结果与渠道同步状态分开呈现 |

## 核心设计

**版本化调整（`adjustments`）** — 每次延期/提前结束在同一 `group_id` 下生成
`version+1` 的新行，原版本只流转状态（`approved → superseded / expired`），永不删除。

**渠道发布（`channel_publishes`）** — 每个版本 × 渠道一条当前状态
（`pending / success / failed / stale`），重试在同一行累加 `attempts`。

**回执日志（`publish_receipts`）** — 每条回执只追加。回执是否采纳由
`ReceiptHandler` 判定，两个条件同时满足才允许改写渠道当前状态：

1. 该发布仍是此 (门店, 渠道) 上**最新的一次发布意图**（之后没有更新版本的发布或恢复任务）；
2. 对应版本仍处于匹配状态（apply 发布要求版本仍 `approved`，restore 发布要求版本已 `expired`）。

不满足的回执记录 `accepted=false` 并把发布标记 `stale` —— **晚到的旧回执无法覆盖后来批准的时间**。

**渠道当前状态（`channel_states`）** — 每个门店 × 渠道此刻对外显示什么，只有被采纳的回执能写这张表。

**到期恢复（`adjustments:expire`，每分钟调度）** — 只查询 `status = approved`
且已到期的调整：转为 `expired` 并向各渠道发布恢复常规时间。被延期取代的旧版本
状态是 `superseded`，永远不会被捞起 —— **延期后旧恢复任务不会生效**。

**渠道模拟（`config/channels.php`）**

| 渠道 | 模拟行为 |
| --- | --- |
| 官网 website | 立即成功回执 |
| 店内屏 screen | 每次发布第 1 次尝试失败（模拟设备离线），重试后成功 |
| 外卖平台 delivery | 先受理，8 秒后经队列异步回执（模拟延迟/乱序回执） |

## 验证（`tests/Feature/AdjustmentFlowTest.php`，5 个测试 / 57 断言）

- `test_partial_publish_failure_can_be_retried_per_channel` — 部分发布失败可单独补发，不影响已成功渠道
- `test_old_restore_task_does_not_run_after_extension` — 延期后旧恢复任务不生效，只有新版本到期才恢复
- `test_late_receipt_does_not_overwrite_newer_approved_version` — 晚到回执不改错最新时间，且旧版本禁止重试
- `test_calendar_shows_only_published_hours_not_pending_drafts` — 日历不把审批中草稿当作已发布内容
- `test_versions_are_retained_after_revision` — 延期后各版本完整保留

## API 摘要

```
GET  /api/stores                              门店列表（含各渠道当前状态）
GET  /api/stores/{id}/calendar?from&to        日历：每日生效时间 + 审批中草稿
GET  /api/stores/{id}/adjustments             调整记录（按组聚合的版本历史）
POST /api/stores/{id}/adjustments             提交新申请
POST /api/adjustments/{id}/revise             延期/提前结束 → 新版本
POST /api/adjustments/{id}/approve|reject     总部审核
GET  /api/channel-status?store_id=            每次发布状态 + 回执日志
GET  /api/channel-states                      各渠道当前显示内容
POST /api/channel-publishes/{id}/retry        失败渠道单独重试
```
