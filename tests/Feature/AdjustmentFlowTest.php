<?php

namespace Tests\Feature;

use App\Jobs\DeliverChannelReceipt;
use App\Models\Adjustment;
use App\Models\ChannelPublish;
use App\Models\ChannelState;
use App\Models\PublishReceipt;
use App\Models\Store;
use App\Services\ReceiptHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdjustmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->store = Store::first();
    }

    private function submit(array $overrides = []): array
    {
        return $this->postJson("/api/stores/{$this->store->id}/adjustments", array_merge([
            'type' => 'closure',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'reason' => '设备检修',
        ], $overrides))->assertCreated()->json();
    }

    /** 审批中的草稿不能出现在日历生效时间里，批准后才生效 */
    public function test_calendar_shows_only_published_hours_not_pending_drafts(): void
    {
        Queue::fake();

        $this->submit();
        $date = now()->addDay()->toDateString();

        $calendar = $this->getJson("/api/stores/{$this->store->id}/calendar")->assertOk()->json();
        $day = collect($calendar['days'])->firstWhere('date', $date);
        $this->assertSame('regular', $day['type'], '审批中的草稿不能被当作已发布内容');
        $this->assertCount(1, $calendar['pending_drafts']);

        $this->postJson('/api/adjustments/1/approve', ['reviewer' => '总部'])->assertOk();

        $calendar = $this->getJson("/api/stores/{$this->store->id}/calendar")->json();
        $day = collect($calendar['days'])->firstWhere('date', $date);
        $this->assertSame('closure', $day['type'], '批准后日历才显示调整时间');
    }

    /** 部分渠道发布失败时，可以只对失败渠道单独补发 */
    public function test_partial_publish_failure_can_be_retried_per_channel(): void
    {
        Queue::fake(); // 外卖平台异步回执先按住

        $adjustment = $this->submit();
        $this->postJson("/api/adjustments/{$adjustment['id']}/approve", ['reviewer' => '总部'])->assertOk();

        $publishes = ChannelPublish::all()->keyBy('channel');
        $this->assertSame('success', $publishes['website']->status);
        $this->assertSame('failed', $publishes['screen']->status, '店内屏首次发布模拟失败');
        $this->assertSame('pending', $publishes['delivery']->status, '外卖平台等待异步回执');

        // 只有官网写入了渠道当前状态
        $this->assertSame(['website'], ChannelState::pluck('channel')->all());

        // 单独重试失败的店内屏
        $this->postJson("/api/channel-publishes/{$publishes['screen']->id}/retry")
            ->assertOk()
            ->assertJson(['status' => 'success', 'attempts' => 2]);

        $this->assertSame(['screen', 'website'], ChannelState::pluck('channel')->sort()->values()->all(), '外卖平台回执未到，不应写入渠道状态');
        $this->assertSame(1, $publishes['website']->refresh()->attempts, '重试店内屏不影响已成功的官网');

        // 放开外卖平台的延迟回执
        Queue::pushed(DeliverChannelReceipt::class)->each(
            fn ($job) => $job->handle(app(ReceiptHandler::class))
        );
        $this->assertSame('success', $publishes['delivery']->refresh()->status);
        $this->assertCount(3, ChannelState::all());
    }

    /** 延期生成新版本后，旧版本的到期恢复任务不会再执行 */
    public function test_old_restore_task_does_not_run_after_extension(): void
    {
        Queue::fake();

        // v1：1 小时后开始临时停业 2 小时
        $v1 = $this->submit([
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->addHours(3)->toDateTimeString(),
        ]);
        $this->postJson("/api/adjustments/{$v1['id']}/approve", ['reviewer' => '总部'])->assertOk();

        // 延期：v2 结束时间推迟到 6 小时后
        $v2 = $this->postJson("/api/adjustments/{$v1['id']}/revise", [
            'starts_at' => now()->addHour()->toDateTimeString(),
            'ends_at' => now()->addHours(6)->toDateTimeString(),
            'reason' => '检修延期',
        ])->assertCreated()->json();
        $this->assertSame(2, $v2['version']);
        $this->postJson("/api/adjustments/{$v2['id']}/approve", ['reviewer' => '总部'])->assertOk();

        $this->assertSame('superseded', Adjustment::find($v1['id'])->status);
        $this->assertSame('approved', Adjustment::find($v2['id'])->status);

        // 时间推进到 v1 原结束时间之后、v2 结束之前 → 旧恢复任务不应生效
        $this->travel(4)->hours();
        $this->artisan('adjustments:expire')->assertSuccessful();

        $this->assertSame('superseded', Adjustment::find($v1['id'])->status, '旧版本已被取代，恢复任务不得处理它');
        $this->assertSame('approved', Adjustment::find($v2['id'])->status, '延期后的版本仍在有效期内');
        $this->assertSame(0, ChannelPublish::where('kind', 'restore')->count(), '不应产生任何恢复发布');

        // 时间推进到 v2 结束之后 → 只有 v2 被恢复
        $this->travel(3)->hours();
        $this->artisan('adjustments:expire')->assertSuccessful();

        $this->assertSame('expired', Adjustment::find($v2['id'])->status);
        $this->assertSame(3, ChannelPublish::where('kind', 'restore')->where('adjustment_id', $v2['id'])->count());
        $this->assertSame(0, ChannelPublish::where('kind', 'restore')->where('adjustment_id', $v1['id'])->count());

        // 官网渠道已恢复显示常规营业时间
        $state = ChannelState::where('channel', 'website')->first();
        $this->assertSame('regular', $state->payload['type']);
        $this->assertNull($state->adjustment_id);
    }

    /** 晚到的旧版本回执不能覆盖后来批准的时间 */
    public function test_late_receipt_does_not_overwrite_newer_approved_version(): void
    {
        Queue::fake();

        // v1 临时停业，批准；外卖平台回执被按住（模拟网络延迟）
        $v1 = $this->submit();
        $this->postJson("/api/adjustments/{$v1['id']}/approve", ['reviewer' => '总部'])->assertOk();
        $this->assertSame(1, Queue::pushed(DeliverChannelReceipt::class)->count());
        $lateJob = Queue::pushed(DeliverChannelReceipt::class)->first();

        // 门店改为延长营业（v2），总部批准
        $v2 = $this->postJson("/api/adjustments/{$v1['id']}/revise", [
            'type' => 'extended',
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(2)->toDateTimeString(),
            'open_time' => '08:00',
            'close_time' => '23:30',
            'reason' => '商圈活动，改为延长营业',
        ])->assertCreated()->json();
        $this->postJson("/api/adjustments/{$v2['id']}/approve", ['reviewer' => '总部'])->assertOk();

        // v2 自己的外卖回执先正常到达
        $v2Job = Queue::pushed(DeliverChannelReceipt::class)->slice(-1)->first();
        $v2Job->handle(app(ReceiptHandler::class));

        $state = ChannelState::where('channel', 'delivery')->first();
        $this->assertSame('extended', $state->payload['type']);
        $this->assertSame('08:00', $state->payload['open']);
        $this->assertSame('23:30', $state->payload['close']);

        // 此刻 v1 的迟到回执才送达 —— 不得覆盖 v2 的时间
        $lateJob->handle(app(ReceiptHandler::class));

        $state->refresh();
        $this->assertSame('extended', $state->payload['type'], '迟到回执不得覆盖最新批准的时间');
        $this->assertSame('23:30', $state->payload['close']);
        $this->assertSame($v2['id'], $state->adjustment_id);

        // 迟到回执被如实记录：未采纳
        $v1DeliveryPublish = ChannelPublish::where('adjustment_id', $v1['id'])->where('channel', 'delivery')->first();
        $this->assertSame('stale', $v1DeliveryPublish->status);
        $receipt = PublishReceipt::where('channel_publish_id', $v1DeliveryPublish->id)->latest('id')->first();
        $this->assertFalse($receipt->accepted);
        $this->assertSame(1, $receipt->version);

        // 旧版本的失败发布也不允许再重试
        $v1ScreenPublish = ChannelPublish::where('adjustment_id', $v1['id'])->where('channel', 'screen')->first();
        $this->postJson("/api/channel-publishes/{$v1ScreenPublish->id}/retry")->assertStatus(422);
    }

    /** 同一调整的各版本都完整保留 */
    public function test_versions_are_retained_after_revision(): void
    {
        Queue::fake();

        $v1 = $this->submit();
        $this->postJson("/api/adjustments/{$v1['id']}/approve", ['reviewer' => '总部']);
        $v2 = $this->postJson("/api/adjustments/{$v1['id']}/revise", [
            'starts_at' => now()->addDay()->toDateTimeString(),
            'ends_at' => now()->addDays(3)->toDateTimeString(),
        ])->assertCreated()->json();
        $this->postJson("/api/adjustments/{$v2['id']}/approve", ['reviewer' => '总部']);

        $groups = $this->getJson("/api/stores/{$this->store->id}/adjustments")->assertOk()->json();
        $this->assertCount(1, $groups);
        $this->assertSame([1, 2], collect($groups[0]['versions'])->pluck('version')->all());
        $this->assertSame('superseded', $groups[0]['versions'][0]['status']);
        $this->assertSame('approved', $groups[0]['versions'][1]['status']);
    }
}
