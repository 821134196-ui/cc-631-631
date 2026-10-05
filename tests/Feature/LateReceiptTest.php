<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\ChannelDirective;
use App\Models\ChannelState;
use App\Models\PublicationChannel;
use App\Services\AdjustmentService;
use App\Services\PublishService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 验证：晚到的旧回执不会改错渠道上的最新时间。
 * 渠道状态带 epoch 闸门，旧发布的回执到达时只记录、不写入。
 */
class LateReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_late_receipt_does_not_overwrite_newer_publication(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        // 注入：店内屏的下一次回执延迟 120 秒到达
        ChannelDirective::create([
            'store_id' => $store->id,
            'channel' => 'screen',
            'mode' => ChannelDirective::MODE_DELAY_ONCE,
            'delay_seconds' => 120,
        ]);

        // v1 批准：官网/外卖立即成功，店内屏回执在路上
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

        $pendingScreen = PublicationChannel::where('channel', 'screen')->sole();
        $this->assertSame(PublicationChannel::STATUS_PENDING, $pendingScreen->status);
        $this->assertNull(ChannelState::where('store_id', $store->id)->where('channel', 'screen')->first());

        // 回执到达前，门店又延期：v2 批准，店内屏这次立即成功（应用了 v2 的时间）
        $v2 = $service->revise($v1->fresh(), [
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-12 23:00:00',
            'open_time' => '07:30',
            'close_time' => '23:30',
            'reason' => '客流高峰延长',
            'submitted_by' => '店长',
        ]);
        $service->approve($v2, '总部');

        $screenState = ChannelState::where('store_id', $store->id)->where('channel', 'screen')->sole();
        $this->assertSame(2, $screenState->content['version']);
        $this->assertSame('23:30', $screenState->content['close_time']);
        $newerEpoch = $screenState->applied_epoch;

        // 此时 v1 的迟到回执才送达：必须被识别为过期，不得覆盖 v2 的时间
        Carbon::setTestNow('2026-10-05 10:03:00');
        $delivered = app(PublishService::class)->deliverDue();
        $this->assertSame(1, $delivered);

        $lateReceipt = $pendingScreen->fresh();
        $this->assertSame(PublicationChannel::STATUS_STALE, $lateReceipt->status);

        $screenState = ChannelState::where('store_id', $store->id)->where('channel', 'screen')->sole();
        $this->assertSame($newerEpoch, $screenState->applied_epoch);
        $this->assertSame(2, $screenState->content['version']);
        $this->assertSame('23:30', $screenState->content['close_time']);
        $this->assertSame('07:30', $screenState->content['open_time']);

        Carbon::setTestNow();
    }

    public function test_retry_of_old_failed_receipt_becomes_stale_after_newer_publication(): void
    {
        Carbon::setTestNow('2026-10-05 10:00:00');
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        // 外卖平台对 v1 的发布失败一次
        ChannelDirective::create([
            'store_id' => $store->id,
            'channel' => 'delivery',
            'mode' => ChannelDirective::MODE_FAIL_ONCE,
        ]);

        $v1 = $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-08 23:00:00',
            'reason' => '检修',
            'submitted_by' => '店长',
        ]);
        $service->approve($v1, '总部');
        $failed = PublicationChannel::where('channel', 'delivery')->sole();
        $this->assertSame(PublicationChannel::STATUS_FAILED, $failed->status);

        // 还没来得及重试，门店提前结束：v2 批准，外卖平台这次成功
        $v2 = $service->revise($v1->fresh(), [
            'start_at' => '2026-10-06 00:00:00',
            'end_at' => '2026-10-07 12:00:00',
            'reason' => '检修提前完成',
            'submitted_by' => '店长',
        ]);
        $service->approve($v2, '总部');

        $state = ChannelState::where('store_id', $store->id)->where('channel', 'delivery')->sole();
        $this->assertSame(2, $state->content['version']);

        // 此时再补发 v1 的失败回执：内容已过时，标记 stale_ignored，不改动渠道
        app(PublishService::class)->retry($failed->fresh());
        $this->assertSame(PublicationChannel::STATUS_STALE, $failed->fresh()->status);

        $state = ChannelState::where('store_id', $store->id)->where('channel', 'delivery')->sole();
        $this->assertSame(2, $state->content['version']);
        $this->assertSame('2026-10-07 12:00:00', $state->content['end_at']);

        Carbon::setTestNow();
    }
}
