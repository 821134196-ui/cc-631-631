<?php

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Services\AdjustmentService;
use App\Services\EffectiveHoursService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 门店日历只显示"当前实际对外生效"的营业时间：
 * 审核中的草稿、已驳回、已被取代的版本都不能出现在日历上。
 */
class CalendarEffectiveHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_draft_never_appears_on_calendar(): void
    {
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => now()->addDays(2)->startOfDay(),
            'end_at' => now()->addDays(3)->endOfDay(),
            'reason' => '设备检修',
            'submitted_by' => '店长',
        ]);

        $hours = app(EffectiveHoursService::class);
        $day = now()->addDays(2)->toDateString();
        $result = $hours->forRange($store, Carbon::parse($day), Carbon::parse($day));

        // 草稿不进日历：当天仍是基础时间
        $this->assertSame('base', $result['days'][0]['source']);
        $this->assertFalse($result['days'][0]['is_closed']);
        $this->assertSame('09:00', $result['days'][0]['open']);
        // 草稿单独返回，供前端以"未生效"形式提示
        $this->assertCount(1, $result['drafts']);
    }

    public function test_approved_adjustment_appears_and_superseded_version_is_replaced(): void
    {
        $store = $this->makeStore();
        $service = app(AdjustmentService::class);

        $v1 = $service->submit($store, [
            'type' => Adjustment::TYPE_EXTENDED,
            'start_at' => now()->addDays(2)->startOfDay(),
            'end_at' => now()->addDays(3)->endOfDay(),
            'open_time' => '08:00',
            'close_time' => '23:00',
            'reason' => '客流高峰',
            'submitted_by' => '店长',
        ]);
        $service->approve($v1, '总部');

        $hours = app(EffectiveHoursService::class);
        $day = now()->addDays(2)->toDateString();
        $result = $hours->forRange($store->fresh(), Carbon::parse($day), Carbon::parse($day));
        $this->assertSame('adjustment', $result['days'][0]['source']);
        $this->assertSame('08:00', $result['days'][0]['open']);
        $this->assertSame('23:00', $result['days'][0]['close']);

        // 延期：v2 批准后，日历只认 v2 的窗口与时间
        $v2 = $service->revise($v1->fresh(), [
            'start_at' => now()->addDays(2)->startOfDay(),
            'end_at' => now()->addDays(6)->endOfDay(),
            'open_time' => '07:30',
            'close_time' => '23:30',
            'reason' => '客流高峰延长',
            'submitted_by' => '店长',
        ]);
        $service->approve($v2, '总部');

        $this->assertSame(Adjustment::STATUS_SUPERSEDED, $v1->fresh()->status);

        $day5 = now()->addDays(5)->toDateString();
        $result = $hours->forRange($store->fresh(), Carbon::parse($day5), Carbon::parse($day5));
        $this->assertSame('adjustment', $result['days'][0]['source']);
        $this->assertSame('07:30', $result['days'][0]['open']);
        $this->assertSame(2, $result['days'][0]['adjustment']['version']);
    }
}
