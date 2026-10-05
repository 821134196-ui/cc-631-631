<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\ChannelPublish;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AdjustmentService
{
    public function __construct(private ChannelPublisher $publisher) {}

    /** 门店提交一次新的临时调整申请（版本 1，待审批） */
    public function submit(Store $store, array $data): Adjustment
    {
        return Adjustment::create([
            'store_id' => $store->id,
            'group_id' => (string) Str::uuid(),
            'version' => 1,
            'type' => $data['type'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'open_time' => $data['open_time'] ?? null,
            'close_time' => $data['close_time'] ?? null,
            'reason' => $data['reason'] ?? null,
            'status' => Adjustment::STATUS_PENDING,
            'submitted_by' => $data['submitted_by'] ?? null,
        ]);
    }

    /**
     * 基于当前版本生成新版本（延期 / 提前结束 / 改时段）。
     * 原版本行保留不动，新版本进入待审批；批准后才取代原版本。
     */
    public function revise(Adjustment $current, array $data): Adjustment
    {
        if (! $current->isLatestVersion()) {
            throw new InvalidArgumentException('只能基于该调整的最新版本生成新版本');
        }
        if ($current->status !== Adjustment::STATUS_APPROVED) {
            throw new InvalidArgumentException('只有已批准且生效中的调整才能延期或提前结束');
        }

        return Adjustment::create([
            'store_id' => $current->store_id,
            'group_id' => $current->group_id,
            'version' => $current->version + 1,
            'supersedes_id' => $current->id,
            'type' => $data['type'] ?? $current->type,
            'starts_at' => $data['starts_at'] ?? $current->starts_at,
            'ends_at' => $data['ends_at'] ?? $current->ends_at,
            'open_time' => array_key_exists('open_time', $data) ? $data['open_time'] : $current->open_time,
            'close_time' => array_key_exists('close_time', $data) ? $data['close_time'] : $current->close_time,
            'reason' => $data['reason'] ?? $current->reason,
            'status' => Adjustment::STATUS_PENDING,
            'submitted_by' => $data['submitted_by'] ?? $current->submitted_by,
        ]);
    }

    /**
     * 总部批准：旧批准版本转为 superseded（其未完成的发布标记 stale），
     * 本版本转为 approved 并向全部渠道发起发布。
     */
    public function approve(Adjustment $adjustment, string $reviewer, ?string $note = null): Adjustment
    {
        if ($adjustment->status !== Adjustment::STATUS_PENDING) {
            throw new InvalidArgumentException('该申请不在待审批状态');
        }
        if (! $adjustment->isLatestVersion()) {
            throw new InvalidArgumentException('该版本已有更新的版本，不能批准旧版本');
        }

        $publishes = DB::transaction(function () use ($adjustment, $reviewer, $note) {
            // 同组上一个已批准版本 → 被取代
            Adjustment::where('group_id', $adjustment->group_id)
                ->where('id', '!=', $adjustment->id)
                ->where('status', Adjustment::STATUS_APPROVED)
                ->each(function (Adjustment $old) {
                    $old->update(['status' => Adjustment::STATUS_SUPERSEDED]);
                    // 旧版本还没送达的发布直接作废，迟到回执也会被回执处理器判旧
                    $old->publishes()->where('status', ChannelPublish::STATUS_PENDING)
                        ->update(['status' => ChannelPublish::STATUS_STALE]);
                });

            $adjustment->update([
                'status' => Adjustment::STATUS_APPROVED,
                'reviewed_by' => $reviewer,
                'reviewed_at' => now(),
                'review_note' => $note,
            ]);

            $created = [];
            foreach (array_keys(config('channels.channels')) as $channel) {
                $created[] = ChannelPublish::create([
                    'adjustment_id' => $adjustment->id,
                    'channel' => $channel,
                    'kind' => ChannelPublish::KIND_APPLY,
                    'status' => ChannelPublish::STATUS_PENDING,
                ]);
            }

            return $created;
        });

        foreach ($publishes as $publish) {
            $this->publisher->attempt($publish);
        }

        return $adjustment->refresh();
    }

    public function reject(Adjustment $adjustment, string $reviewer, ?string $note = null): Adjustment
    {
        if ($adjustment->status !== Adjustment::STATUS_PENDING) {
            throw new InvalidArgumentException('该申请不在待审批状态');
        }

        $adjustment->update([
            'status' => Adjustment::STATUS_REJECTED,
            'reviewed_by' => $reviewer,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        return $adjustment;
    }
}
