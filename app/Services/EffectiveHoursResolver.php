<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Store;
use Carbon\CarbonImmutable;

/**
 * 计算门店日历上“当前实际对外生效”的营业时间。
 * 只考虑已批准（approved）的版本；审批中的草稿绝不进入结果。
 */
class EffectiveHoursResolver
{
    /**
     * @return array<string, array> date(Y-m-d) => 生效信息
     */
    public function forRange(Store $store, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $approved = Adjustment::where('store_id', $store->id)
            ->where('status', Adjustment::STATUS_APPROVED)
            ->where('starts_at', '<', $to->addDay()->startOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->orderBy('reviewed_at')
            ->get();

        $days = [];
        for ($day = $from; $day->lte($to); $day = $day->addDay()) {
            $covering = $approved
                ->filter(fn (Adjustment $a) => $a->starts_at < $day->addDay()->startOfDay()
                    && $a->ends_at > $day->startOfDay())
                ->sortByDesc('reviewed_at')
                ->first();

            $days[$day->toDateString()] = $covering
                ? $this->fromAdjustment($covering)
                : $this->regular($store);
        }

        return $days;
    }

    private function fromAdjustment(Adjustment $a): array
    {
        return [
            'type' => $a->type,
            'open' => $a->type === Adjustment::TYPE_CLOSURE ? null : $a->open_time,
            'close' => $a->type === Adjustment::TYPE_CLOSURE ? null : $a->close_time,
            'adjustment_id' => $a->id,
            'version' => $a->version,
            'label' => $a->type === Adjustment::TYPE_CLOSURE
                ? '临时停业'
                : "调整营业 {$a->open_time}-{$a->close_time}",
        ];
    }

    private function regular(Store $store): array
    {
        return [
            'type' => 'regular',
            'open' => $store->regular_open,
            'close' => $store->regular_close,
            'adjustment_id' => null,
            'version' => null,
            'label' => "正常营业 {$store->regular_open}-{$store->regular_close}",
        ];
    }
}
