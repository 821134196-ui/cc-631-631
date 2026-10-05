<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\ChannelDirective;
use App\Models\ChannelState;
use App\Models\PublicationChannel;
use App\Services\AdjustmentService;
use App\Services\PublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 验证：一次发布中部分渠道失败时，成功渠道不受影响，
 * 失败渠道可以单独重试补发，且重试只作用于失败的那一条。
 */
class PartialFailureRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_failure_can_be_retried_per_channel(): void
    {
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        // 注入：外卖平台下次发布失败一次
        ChannelDirective::create([
            'store_id' => $store->id,
            'channel' => 'delivery',
            'mode' => ChannelDirective::MODE_FAIL_ONCE,
            'message' => '外卖平台接口超时（模拟）',
        ]);

        $adjustment = $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => now()->addDay()->startOfDay(),
            'end_at' => now()->addDays(2)->endOfDay(),
            'reason' => '设备检修',
            'submitted_by' => '店长',
        ]);
        $service->approve($adjustment, '总部');

        // 官网、店内屏成功；外卖平台失败
        $receipts = PublicationChannel::query()->get()->keyBy('channel');
        $this->assertSame(PublicationChannel::STATUS_SUCCESS, $receipts['website']->status);
        $this->assertSame(PublicationChannel::STATUS_SUCCESS, $receipts['screen']->status);
        $this->assertSame(PublicationChannel::STATUS_FAILED, $receipts['delivery']->status);
        $this->assertSame('外卖平台接口超时（模拟）', $receipts['delivery']->last_error);

        // 成功渠道的内容已生效
        $websiteState = ChannelState::where('store_id', $store->id)->where('channel', 'website')->first();
        $this->assertSame('adjustment', $websiteState->content['display']);
        $this->assertNull(ChannelState::where('store_id', $store->id)->where('channel', 'delivery')->first());

        // 单独重试外卖平台 → 成功；成功渠道的尝试次数不变（未被重复打扰）
        app(PublishService::class)->retry($receipts['delivery']->fresh());

        $after = PublicationChannel::query()->get()->keyBy('channel');
        $this->assertSame(PublicationChannel::STATUS_SUCCESS, $after['delivery']->status);
        $this->assertSame(2, $after['delivery']->attempts);
        $this->assertSame(1, $after['website']->attempts);
        $this->assertSame(1, $after['screen']->attempts);

        // 三个渠道现在都展示调整内容
        $deliveryState = ChannelState::where('store_id', $store->id)->where('channel', 'delivery')->first();
        $this->assertSame('adjustment', $deliveryState->content['display']);
        $this->assertSame($websiteState->applied_epoch, $deliveryState->applied_epoch);
    }

    public function test_only_failed_receipts_can_be_retried(): void
    {
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        $adjustment = $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => now()->addDay()->startOfDay(),
            'end_at' => now()->addDays(2)->endOfDay(),
            'reason' => '设备检修',
            'submitted_by' => '店长',
        ]);
        $service->approve($adjustment, '总部');

        $success = PublicationChannel::where('channel', 'website')->first();
        $this->expectException(\RuntimeException::class);
        app(PublishService::class)->retry($success);
    }
}
