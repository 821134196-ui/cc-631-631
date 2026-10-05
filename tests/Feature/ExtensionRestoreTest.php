<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\ChannelState;
use App\Models\Publication;
use App\Models\RestoreTask;
use App\Services\AdjustmentService;
use App\Services\RestoreService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 验证：延期/提前结束生成新版本后，旧版本的到期恢复任务不会生效，
 * 不会在延期后误把渠道恢复成原营业时间；恢复只针对仍然有效的调整执行。
 */
class ExtensionRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_restore_task_does_not_fire_after_extension(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        // v1：10-06 ~ 10-08 延长营业，批准后生成恢复任务（run_at = 10-08 末）
        $v1 = $service->submit($store, [
            'type' => Adjustment::TYPE_EXTENDED,
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-08 23:00:00',
            'open_time' => '08:00',
            'close_time' => '23:00',
            'reason' => '客流高峰',
            'submitted_by' => '店长',
        ]);
        $service->approve($v1, '总部');
        $oldTask = RestoreTask::where('adjustment_id', $v1->id)->sole();
        $this->assertSame(RestoreTask::STATUS_PENDING, $oldTask->status);

        // 延期到 10-12：生成 v2 并批准
        $v2 = $service->revise($v1->fresh(), [
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-12 23:00:00',
            'reason' => '客流高峰延长',
            'submitted_by' => '店长',
        ]);
        $service->approve($v2, '总部');

        // 原调整记录保留（superseded），旧恢复任务被取消
        $this->assertSame(Adjustment::STATUS_SUPERSEDED, $v1->fresh()->status);
        $this->assertSame(RestoreTask::STATUS_CANCELLED, $oldTask->fresh()->status);
        $newTask = RestoreTask::where('adjustment_id', $v2->id)->sole();
        $this->assertSame(RestoreTask::STATUS_PENDING, $newTask->status);

        // 时间走到旧窗口结束之后、新窗口结束之前：跑恢复检查
        Carbon::setTestNow('2026-10-09 08:00:00');
        $result = app(RestoreService::class)->runDue();

        // 旧任务已被取消，不会执行；没有任何恢复发布
        $this->assertSame(0, $result['executed']);
        $this->assertSame(0, Publication::where('kind', Publication::KIND_RESTORE)->count());

        // 渠道上仍是 v2 的延长时间，没有被误恢复
        $state = ChannelState::where('store_id', $store->id)->where('channel', 'website')->sole();
        $this->assertSame('adjustment', $state->content['display']);
        $this->assertSame(2, $state->content['version']);
        $this->assertSame('23:00', $state->content['close_time']);

        // 时间走到新窗口结束之后：恢复任务才执行
        Carbon::setTestNow('2026-10-13 00:30:00');
        $result = app(RestoreService::class)->runDue();
        $this->assertSame(1, $result['executed']);
        $this->assertSame(RestoreTask::STATUS_DONE, $newTask->fresh()->status);
        $this->assertSame(Adjustment::STATUS_EXPIRED, $v2->fresh()->status);

        $state = ChannelState::where('store_id', $store->id)->where('channel', 'website')->sole();
        $this->assertSame('base', $state->content['display']);

        Carbon::setTestNow();
    }

    public function test_restore_task_left_in_queue_is_skipped_when_adjustment_superseded(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        $v1 = $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-08 23:00:00',
            'reason' => '检修',
            'submitted_by' => '店长',
        ]);
        $service->approve($v1, '总部');

        $v2 = $service->revise($v1->fresh(), [
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-10 23:00:00',
            'reason' => '检修延期',
            'submitted_by' => '店长',
        ]);
        $service->approve($v2, '总部');

        // 模拟"旧任务仍残留在队列里"的极端情况（例如取消时漏网）：
        // 直接把它改回 pending，执行器必须复核并跳过，绝不能误恢复。
        $zombie = RestoreTask::where('adjustment_id', $v1->id)->sole();
        $zombie->update(['status' => RestoreTask::STATUS_PENDING, 'executed_at' => null, 'result_note' => null]);

        Carbon::setTestNow('2026-10-09 08:00:00');
        $result = app(RestoreService::class)->runDue();

        $this->assertSame(0, $result['executed']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(RestoreTask::STATUS_SKIPPED, $zombie->fresh()->status);
        $this->assertSame(0, Publication::where('kind', Publication::KIND_RESTORE)->count());

        // 渠道仍是 v2 停业内容
        $state = ChannelState::where('store_id', $store->id)->where('channel', 'screen')->sole();
        $this->assertSame('adjustment', $state->content['display']);
        $this->assertSame(2, $state->content['version']);

        Carbon::setTestNow();
    }
}
