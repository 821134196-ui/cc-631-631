<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\ChannelPublish;
use App\Models\ChannelState;
use App\Models\PublishReceipt;
use Illuminate\Support\Facades\DB;

/**
 * 回执处理器 —— 系统正确性的核心。
 *
 * 一条回执只有在“仍然有效”时才允许改写渠道当前状态：
 *  1. 它所属的发布仍是该 (门店, 渠道) 上最新的一次发布意图
 *     （之后没有更新的发布记录，例如新版本批准或到期恢复）；
 *  2. 它对应的调整版本仍处于匹配状态
 *     （apply 发布要求版本仍 approved；restore 发布要求版本已 expired）。
 *
 * 不满足任一条件的回执只记入日志（accepted=false），并把对应发布标记为
 * stale，绝不回写 channel_states —— 因此晚到的旧回执无法覆盖后来批准的时间。
 */
class ReceiptHandler
{
    public function isActionable(ChannelPublish $publish): bool
    {
        $adjustment = $publish->adjustment;

        $hasNewerPublish = ChannelPublish::where('channel', $publish->channel)
            ->where('id', '>', $publish->id)
            ->whereHas('adjustment', fn ($q) => $q->where('store_id', $adjustment->store_id))
            ->exists();

        if ($hasNewerPublish) {
            return false;
        }

        return $publish->kind === ChannelPublish::KIND_APPLY
            ? $adjustment->status === Adjustment::STATUS_APPROVED
            : $adjustment->status === Adjustment::STATUS_EXPIRED;
    }

    /**
     * 记录一条渠道回执，并按上述规则决定是否采纳。
     */
    public function record(ChannelPublish $publish, string $result, array $payload = [], ?string $note = null): PublishReceipt
    {
        return DB::transaction(function () use ($publish, $result, $payload, $note) {
            $publish->refresh();
            $accepted = $this->isActionable($publish);

            $receipt = PublishReceipt::create([
                'channel_publish_id' => $publish->id,
                'adjustment_id' => $publish->adjustment_id,
                'channel' => $publish->channel,
                'version' => $publish->adjustment->version,
                'attempt' => $publish->attempts,
                'result' => $result,
                'payload' => $payload ?: null,
                'accepted' => $accepted,
                'note' => $accepted ? $note : ($note ? $note.'；' : '').'回执到达时已过期：存在更新的批准版本或恢复任务，未采纳',
            ]);

            if (! $accepted) {
                $publish->update(['status' => ChannelPublish::STATUS_STALE]);

                return $receipt;
            }

            if ($result === 'success') {
                $publish->update([
                    'status' => ChannelPublish::STATUS_SUCCESS,
                    'published_at' => now(),
                    'last_error' => null,
                ]);
                $this->applyToChannelState($publish);
            } else {
                $publish->update([
                    'status' => ChannelPublish::STATUS_FAILED,
                    'last_error' => $note ?? '渠道返回失败',
                ]);
            }

            return $receipt;
        });
    }

    /** 回执被采纳：改写该 (门店, 渠道) 当前对外显示的内容 */
    private function applyToChannelState(ChannelPublish $publish): void
    {
        $adjustment = $publish->adjustment;

        $state = [
            'adjustment_id' => $publish->kind === ChannelPublish::KIND_APPLY ? $adjustment->id : null,
            'payload' => $this->displayPayload($publish),
            'synced_at' => now(),
        ];

        ChannelState::updateOrCreate(
            ['store_id' => $adjustment->store_id, 'channel' => $publish->channel],
            $state,
        );
    }

    /** 计算这条发布要求渠道展示的内容快照 */
    public function displayPayload(ChannelPublish $publish): array
    {
        $adjustment = $publish->adjustment;
        $store = $adjustment->store;

        if ($publish->kind === ChannelPublish::KIND_RESTORE) {
            return [
                'type' => 'regular',
                'open' => $store->regular_open,
                'close' => $store->regular_close,
                'label' => "恢复正常营业 {$store->regular_open}-{$store->regular_close}",
            ];
        }

        if ($adjustment->type === Adjustment::TYPE_CLOSURE) {
            return [
                'type' => 'closure',
                'open' => null,
                'close' => null,
                'label' => '临时停业 '.$adjustment->starts_at->format('m-d H:i').' 至 '.$adjustment->ends_at->format('m-d H:i'),
            ];
        }

        return [
            'type' => $adjustment->type,
            'open' => $adjustment->open_time,
            'close' => $adjustment->close_time,
            'label' => "营业时间调整 {$adjustment->open_time}-{$adjustment->close_time}（"
                .$adjustment->starts_at->format('m-d').' 至 '.$adjustment->ends_at->format('m-d').'）',
        ];
    }
}
