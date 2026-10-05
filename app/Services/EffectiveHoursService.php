<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Store;
use Carbon\Carbon;

/**
 * 计算门店"当前实际对外生效"的营业时间。
 *
 * 关键约束：只有已批准（approved）或已自然到期（expired，历史上确实生效过）
 * 的调整才参与计算；审核中的草稿（pending）、已驳回（rejected）、
 * 已被新版本取代（superseded）的记录一律不算，保证日历不会把草稿当成已发布内容。
 */
class EffectiveHoursService
{
    /**
     * @return array{days: array<int, array>, drafts: array}
     */
    public function forRange(Store $store, Carbon $from, Carbon $to): array
    {
        $schedules = $store->schedules->keyBy('weekday');

        // 与查询区间有交集、且已生效（或曾生效）的调整
        $adjustments = Adjustment::where('store_id', $store->id)
            ->whereIn('status', [Adjustment::STATUS_APPROVED, Adjustment::STATUS_EXPIRED])
            ->where('start_at', '<', $to->copy()->endOfDay())
            ->where('end_at', '>', $from->copy()->startOfDay())
            ->orderBy('version')->orderBy('id')
            ->get();

        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $days[] = $this->dayEntry($day->copy(), $schedules, $adjustments);
        }

        // 审核中的草稿单独返回，前端仅作"未生效"提示，不进日历
        $drafts = Adjustment::where('store_id', $store->id)
            ->where('status', Adjustment::STATUS_PENDING)
            ->where('end_at', '>', $from->copy()->startOfDay())
            ->orderBy('start_at')
            ->get()
            ->map(fn (Adjustment $a) => $this->adjustmentBrief($a))
            ->all();

        return ['days' => $days, 'drafts' => $drafts];
    }

    private function dayEntry(Carbon $day, $schedules, $adjustments): array
    {
        $weekday = (int) $day->dayOfWeek;
        $base = $schedules->get($weekday);

        $entry = [
            'date' => $day->toDateString(),
            'weekday' => $weekday,
            'is_closed' => $base ? (bool) $base->is_closed : false,
            'open' => $base?->open_time,
            'close' => $base?->close_time,
            'source' => 'base',
            'adjustment' => null,
        ];

        // 覆盖当天的生效调整：同一链只会有当前版本是 approved，取版本最高者兜底
        $covering = $adjustments
            ->filter(fn (Adjustment $a) => $a->start_at < $day->copy()->endOfDay()
                && $a->end_at > $day->copy()->startOfDay())
            ->sortBy([['version', 'asc'], ['id', 'asc']])
            ->last();

        if ($covering) {
            if ($covering->type === Adjustment::TYPE_CLOSURE) {
                $entry['is_closed'] = true;
                $entry['open'] = null;
                $entry['close'] = null;
            } else {
                $entry['is_closed'] = false;
                $entry['open'] = $covering->open_time;
                $entry['close'] = $covering->close_time;
            }
            $entry['source'] = 'adjustment';
            $entry['adjustment'] = $this->adjustmentBrief($covering);
        }

        return $entry;
    }

    private function adjustmentBrief(Adjustment $a): array
    {
        return [
            'id' => $a->id,
            'version' => $a->version,
            'type' => $a->type,
            'status' => $a->status,
            'start_at' => $a->start_at->toDateTimeString(),
            'end_at' => $a->end_at->toDateTimeString(),
            'open_time' => $a->open_time,
            'close_time' => $a->close_time,
            'reason' => $a->reason,
        ];
    }
}
