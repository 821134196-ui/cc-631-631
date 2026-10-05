<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\RestoreTask;
use App\Models\Store;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 调整申请服务：提交、总部审批、延期/提前结束（生成新版本）。
 *
 * 版本链规则：
 *  - 首次提交为 v1（root_id = 自身 id）；
 *  - 延期/提前结束在同一 root_id 下生成 version+1 的 pending 草稿，原版本保留；
 *  - 新版本被批准时，链上旧的 approved 版本置为 superseded，其未执行的
 *    到期恢复任务一并取消 —— 保证旧恢复任务不会在延期后误恢复原时间。
 */
class AdjustmentService
{
    public function __construct(private PublishService $publisher) {}

    /** 门店提交新申请（v1，pending） */
    public function submit(Store $store, array $data): Adjustment
    {
        $this->assertNoOverlap($store->id, $data['start_at'], $data['end_at'], null);

        return DB::transaction(function () use ($store, $data) {
            $adjustment = Adjustment::create([
                'store_id' => $store->id,
                'root_id' => 0, // 占位，保存后改为自身 id
                'version' => 1,
                'type' => $data['type'],
                'start_at' => $data['start_at'],
                'end_at' => $data['end_at'],
                'open_time' => $data['open_time'] ?? null,
                'close_time' => $data['close_time'] ?? null,
                'reason' => $data['reason'],
                'status' => Adjustment::STATUS_PENDING,
                'submitted_by' => $data['submitted_by'],
            ]);
            $adjustment->root_id = $adjustment->id;
            $adjustment->save();

            return $adjustment;
        });
    }

    /**
     * 对已批准的当前版本发起延期/提前结束：生成 version+1 的 pending 草稿。
     * 原版本在新版本批准前继续生效。
     */
    public function revise(Adjustment $current, array $data): Adjustment
    {
        if ($current->status !== Adjustment::STATUS_APPROVED) {
            throw ValidationException::withMessages(['adjustment' => '只有已批准且生效中的调整才能延期或提前结束']);
        }

        $latest = Adjustment::where('root_id', $current->root_id)->max('version');
        if ($current->version !== (int) $latest) {
            throw ValidationException::withMessages(['adjustment' => '只能基于链上最新版本发起变更']);
        }

        $hasPending = Adjustment::where('root_id', $current->root_id)
            ->where('status', Adjustment::STATUS_PENDING)
            ->exists();
        if ($hasPending) {
            throw ValidationException::withMessages(['adjustment' => '该调整已有一个审核中的新版本，请先处理']);
        }

        $this->assertNoOverlap($current->store_id, $data['start_at'], $data['end_at'], $current->root_id);

        return Adjustment::create([
            'store_id' => $current->store_id,
            'root_id' => $current->root_id,
            'version' => $current->version + 1,
            'type' => $current->type,
            'start_at' => $data['start_at'],
            'end_at' => $data['end_at'],
            'open_time' => $data['open_time'] ?? $current->open_time,
            'close_time' => $data['close_time'] ?? $current->close_time,
            'reason' => $data['reason'] ?? $current->reason,
            'status' => Adjustment::STATUS_PENDING,
            'submitted_by' => $data['submitted_by'],
        ]);
    }

    /** 总部批准：旧版本取代 + 取消旧恢复任务 + 全渠道发布 + 登记新恢复任务 */
    public function approve(Adjustment $adjustment, string $reviewer, ?string $note = null): Adjustment
    {
        if ($adjustment->status !== Adjustment::STATUS_PENDING) {
            throw ValidationException::withMessages(['adjustment' => '只有审核中的申请才能批准']);
        }
        if (Carbon::parse($adjustment->end_at)->lte(now())) {
            throw ValidationException::withMessages(['adjustment' => '调整时段已过期，无法批准']);
        }

        $this->assertNoOverlap(
            $adjustment->store_id,
            $adjustment->start_at,
            $adjustment->end_at,
            $adjustment->root_id,
            $adjustment->id,
        );

        DB::transaction(function () use ($adjustment, $reviewer, $note) {
            // 取代链上旧的 approved 版本，取消其未执行的恢复任务
            $previous = Adjustment::where('root_id', $adjustment->root_id)
                ->where('status', Adjustment::STATUS_APPROVED)
                ->where('id', '!=', $adjustment->id)
                ->get();

            foreach ($previous as $old) {
                $old->status = Adjustment::STATUS_SUPERSEDED;
                $old->save();

                RestoreTask::where('adjustment_id', $old->id)
                    ->where('status', RestoreTask::STATUS_PENDING)
                    ->update([
                        'status' => RestoreTask::STATUS_CANCELLED,
                        'result_note' => "已被 v{$adjustment->version} 取代，恢复任务取消",
                        'executed_at' => now(),
                    ]);
            }

            $adjustment->status = Adjustment::STATUS_APPROVED;
            $adjustment->reviewed_by = $reviewer;
            $adjustment->reviewed_at = now();
            $adjustment->review_note = $note;
            $adjustment->save();

            // 到期恢复任务只挂在当前批准的版本上
            RestoreTask::create([
                'adjustment_id' => $adjustment->id,
                'store_id' => $adjustment->store_id,
                'version' => $adjustment->version,
                'run_at' => $adjustment->end_at,
                'status' => RestoreTask::STATUS_PENDING,
            ]);
        });

        $this->publisher->publishAdjustment($adjustment);

        return $adjustment->fresh();
    }

    /** 总部驳回 */
    public function reject(Adjustment $adjustment, string $reviewer, ?string $note = null): Adjustment
    {
        if ($adjustment->status !== Adjustment::STATUS_PENDING) {
            throw ValidationException::withMessages(['adjustment' => '只有审核中的申请才能驳回']);
        }

        $adjustment->status = Adjustment::STATUS_REJECTED;
        $adjustment->reviewed_by = $reviewer;
        $adjustment->reviewed_at = now();
        $adjustment->review_note = $note;
        $adjustment->save();

        return $adjustment;
    }

    /** 同一门店、窗口相交、且仍在 pending/approved 的调整视为冲突（同一条版本链除外） */
    private function assertNoOverlap(int $storeId, $start, $end, ?int $excludeRootId, ?int $excludeId = null): void
    {
        $conflict = Adjustment::where('store_id', $storeId)
            ->whereIn('status', [Adjustment::STATUS_PENDING, Adjustment::STATUS_APPROVED])
            ->when($excludeRootId, fn ($q) => $q->where('root_id', '!=', $excludeRootId))
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->where('start_at', '<', Carbon::parse($end))
            ->where('end_at', '>', Carbon::parse($start))
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'start_at' => "与现有申请（#{$conflict->id} v{$conflict->version}，{$conflict->start_at} ~ {$conflict->end_at}）时段重叠",
            ]);
        }
    }
}
