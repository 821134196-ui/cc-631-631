<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\ChannelDirective;
use App\Models\ChannelState;
use App\Models\Publication;
use App\Models\PublicationChannel;
use App\Models\Store;
use App\Support\Channels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 发布服务：把批准后的内容发往各渠道（官网 / 店内屏 / 外卖平台，本地模拟）。
 *
 * 两条核心安全约束：
 *  1. 每次发布带单调递增的 epoch；回执写入渠道状态时要求 epoch >= 渠道已应用的
 *     applied_epoch，否则标记 stale_ignored —— 晚到的旧回执永远不能覆盖更新的时间。
 *  2. 每个渠道的投递互相独立，失败渠道可单独重试，不影响已成功的渠道。
 */
class PublishService
{
    /** 批准调整后：向所有渠道发布"应用调整" */
    public function publishAdjustment(Adjustment $adjustment): Publication
    {
        $payload = [
            'display' => 'adjustment',
            'adjustment_id' => $adjustment->id,
            'version' => $adjustment->version,
            'type' => $adjustment->type,
            'start_at' => $adjustment->start_at->toDateTimeString(),
            'end_at' => $adjustment->end_at->toDateTimeString(),
            'open_time' => $adjustment->open_time,
            'close_time' => $adjustment->close_time,
            'reason' => $adjustment->reason,
            'summary' => $this->summaryFor($adjustment),
        ];

        return $this->publish($adjustment->store, Publication::KIND_APPLY, $payload, $adjustment->id);
    }

    /** 调整到期后：向所有渠道发布"恢复常规营业时间" */
    public function publishRestore(Store $store, Adjustment $adjustment): Publication
    {
        $payload = [
            'display' => 'base',
            'adjustment_id' => $adjustment->id,
            'version' => $adjustment->version,
            'summary' => '恢复常规营业时间',
        ];

        return $this->publish($store, Publication::KIND_RESTORE, $payload, $adjustment->id);
    }

    private function publish(Store $store, string $kind, array $payload, ?int $adjustmentId): Publication
    {
        $publication = DB::transaction(function () use ($store, $kind, $payload, $adjustmentId) {
            $publication = Publication::create([
                'store_id' => $store->id,
                'adjustment_id' => $adjustmentId,
                'kind' => $kind,
                'payload' => $payload,
                'epoch' => 0,
            ]);
            // epoch 取全局单调递增的发布 id
            $publication->epoch = $publication->id;
            $publication->save();

            foreach (Channels::ALL as $channel) {
                PublicationChannel::create([
                    'publication_id' => $publication->id,
                    'channel' => $channel,
                    'status' => PublicationChannel::STATUS_PENDING,
                ]);
            }

            return $publication;
        });

        foreach ($publication->channelReceipts as $receipt) {
            $this->attempt($receipt);
        }

        return $publication->fresh('channelReceipts');
    }

    /** 失败渠道单独重试（同一 publication，epoch 不变） */
    public function retry(PublicationChannel $receipt): PublicationChannel
    {
        if ($receipt->status !== PublicationChannel::STATUS_FAILED) {
            throw new RuntimeException('只有失败的渠道才能重试');
        }

        return $this->attempt($receipt);
    }

    /**
     * 投递到期的延迟回执（模拟渠道异步回执到达）。
     * 迟到回执同样要过 epoch 闸门：若渠道已应用更新的发布，则标记 stale_ignored。
     *
     * @return int 本次投递的回执数
     */
    public function deliverDue(): int
    {
        $due = PublicationChannel::where('status', PublicationChannel::STATUS_PENDING)
            ->whereNotNull('deliver_after')
            ->where('deliver_after', '<=', now())
            ->get();

        foreach ($due as $receipt) {
            $this->apply($receipt);
        }

        return $due->count();
    }

    /** 向单个渠道发起一次投递（本地模拟渠道 API） */
    private function attempt(PublicationChannel $receipt): PublicationChannel
    {
        $receipt->attempts++;
        $receipt->last_error = null;

        $publication = $receipt->publication;
        $directive = ChannelDirective::activeFor($publication->store_id, $receipt->channel);

        if ($directive) {
            if (in_array($directive->mode, [ChannelDirective::MODE_FAIL_ONCE, ChannelDirective::MODE_FAIL_ALWAYS], true)) {
                if ($directive->mode === ChannelDirective::MODE_FAIL_ONCE) {
                    $directive->consumed = true;
                    $directive->save();
                }
                $receipt->status = PublicationChannel::STATUS_FAILED;
                $receipt->last_error = $directive->message ?: '渠道接口模拟故障';
                $receipt->save();

                return $receipt;
            }

            if ($directive->mode === ChannelDirective::MODE_DELAY_ONCE) {
                $directive->consumed = true;
                $directive->save();
                // 回执延迟到达：保持 pending，等待 deliverDue() 投递
                $receipt->status = PublicationChannel::STATUS_PENDING;
                $receipt->deliver_after = now()->addSeconds($directive->delay_seconds);
                $receipt->save();

                return $receipt;
            }
        }

        return $this->apply($receipt);
    }

    /**
     * 回执真正落地：epoch 闸门 + 写渠道状态（行锁保证并发安全）。
     */
    private function apply(PublicationChannel $receipt): PublicationChannel
    {
        return DB::transaction(function () use ($receipt) {
            $publication = $receipt->publication;

            $state = ChannelState::where('store_id', $publication->store_id)
                ->where('channel', $receipt->channel)
                ->lockForUpdate()
                ->first()
                ?? new ChannelState(['store_id' => $publication->store_id, 'channel' => $receipt->channel, 'applied_epoch' => 0]);

            if ($publication->epoch < $state->applied_epoch) {
                // 晚到的旧回执：只记录回执结果，绝不写渠道内容
                $receipt->status = PublicationChannel::STATUS_STALE;
                $receipt->delivered_at = now();
                $receipt->save();

                return $receipt;
            }

            $state->applied_epoch = $publication->epoch;
            $state->applied_publication_id = $publication->id;
            $state->content = array_merge($publication->payload, [
                'applied_at' => now()->toDateTimeString(),
                'epoch' => $publication->epoch,
            ]);
            $state->save();

            $receipt->status = PublicationChannel::STATUS_SUCCESS;
            $receipt->delivered_at = now();
            $receipt->save();

            return $receipt;
        });
    }

    private function summaryFor(Adjustment $a): string
    {
        $range = $a->start_at->format('Y-m-d H:i').' ~ '.$a->end_at->format('Y-m-d H:i');

        return $a->type === Adjustment::TYPE_CLOSURE
            ? "临时停业：{$range}"
            : "延长营业：{$range}，每日 {$a->open_time}-{$a->close_time}";
    }
}
