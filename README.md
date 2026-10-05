# 连锁商户营业时间临时调整与渠道同步系统

门店临时停业或延长营业时，**只提交一次申请**：总部审核批准后，系统自动把批准后的时间同步到
**官网、店内屏、外卖平台**（渠道均为本地模拟）。门店日历只显示当前实际对外生效的营业时间，
渠道状态页展示每次发布的回执，失败渠道可单独重试。

技术栈：PHP 8.4 · Laravel 13 · React 18 · Eloquent · SQLite。

## 一条命令启动

```bash
bash start.sh          # 打开 http://127.0.0.1:8000
```

脚本自动完成：PHP 环境（内置静态编译 PHP，无需 root）→ 后端依赖 → 前端构建 → SQLite 迁移与演示数据 → 启动服务。

运行验证测试（覆盖下述三个关键场景）：

```bash
.tools/php artisan test
```

## 页面说明

| 页签 | 内容 |
| --- | --- |
| **门店日历** | 当前实际对外生效的营业时间。审核中的草稿只在侧栏以"不对外生效"提示，**不进日历**。 |
| **调整申请** | 提交申请、总部批准/驳回、延期/提前结束（生成新版本，原记录保留）。每条的**审批状态**与**各渠道同步状态**分开展示。 |
| **渠道状态** | 三个渠道当前真实展示的内容、每次发布的回执（成功/失败/等待/晚到忽略）、失败渠道**单独重试**、到期恢复任务列表、本地模拟面板。 |

## 关键设计

- **版本链**：延期/提前结束在同一 `root_id` 下生成 `version+1` 的待审草稿；新版本批准时旧版本置为
  `superseded`（记录保留），其待执行的恢复任务一并取消。
- **防旧回执覆盖**：每次发布带单调递增的 `epoch`；渠道状态记录 `applied_epoch`，回执写入前校验
  `epoch >= applied_epoch`，否则标记 `stale_ignored` —— 晚到的旧回执只记录、不改渠道内容。
- **到期恢复双保险**：① 延期时旧恢复任务立即取消；② 执行器运行前复核"调整仍是 approved 且为链上当前版本"，
  否则标记 `skipped_stale` —— 即使队列里残留旧任务也不会误恢复。
- **草稿隔离**：日历只统计 `approved` / `expired` 状态的调整；`pending` / `rejected` / `superseded` 不参与。

## 手工验证三个场景

**① 部分发布失败可单独补发**
渠道状态页 → 模拟面板选"外卖平台 / 下次发布失败一次" → 注入；调整申请页提交并批准一条申请 →
外卖平台回执失败（官网、店内屏成功）→ 点外卖平台的"单独重试" → 仅该渠道补发成功。

**② 延期后旧恢复任务不会生效**
批准一条调整（恢复任务列表出现"待执行"任务）→ 对其"延期"生成 v2 并批准 → 旧任务变为"因延期被取消"，
渠道仍展示 v2 的时间；点"运行到期恢复检查"也不会恢复（测试中还覆盖了旧任务残留队列被跳过的极端情况）。

**③ 晚到回执不改错最新时间**
模拟面板选"店内屏 / 下次回执延迟到达"（如 45 秒）→ 批准申请 v1（店内屏"等待回执"）→ 立即对 v1 延期生成 v2
并批准（店内屏同步成功，展示 v2 时间）→ 等延迟到期后点"投递到期回执" → v1 的回执标记"晚到已忽略"，
店内屏仍是 v2 的时间。

> 延迟回执与到期恢复也可由调度器自动执行：` .tools/php artisan schedule:work `（每分钟一次）。

## 目录结构

```
app/Models/                Eloquent 模型（Adjustment 版本链、Publication、回执、渠道状态、恢复任务…）
app/Services/              核心业务：EffectiveHoursService / AdjustmentService / PublishService / RestoreService
app/Http/Controllers/      REST API
resources/js/components/   React 三页面：CalendarPage / AdjustmentsPage / ChannelsPage
tests/Feature/             场景验证测试（8 个用例）
database/migrations/       表结构
```

## API 一览

```
GET  /api/stores                          门店与基础周时间表
GET  /api/stores/{id}/calendar            生效日历（+未生效草稿）
GET  /api/stores/{id}/adjustments         调整列表（含版本链与渠道同步状态）
POST /api/stores/{id}/adjustments         提交申请
POST /api/adjustments/{id}/approve        总部批准（触发全渠道发布 + 登记恢复任务）
POST /api/adjustments/{id}/reject         总部驳回
POST /api/adjustments/{id}/revise         延期/提前结束（生成新版本）
GET  /api/stores/{id}/publications        发布记录与回执
POST /api/publication-channels/{id}/retry 失败渠道单独重试
GET  /api/stores/{id}/channel-states      渠道当前展示内容
GET  /api/stores/{id}/restore-tasks       恢复任务列表
POST /api/simulate/directive              注入故障/延迟（fail_once/fail_always/delay_once/clear）
POST /api/simulate/deliver-receipts       投递到期的延迟回执
POST /api/simulate/run-expiry             运行到期恢复检查
```
