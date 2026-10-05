<?php

namespace App\Services;

use App\Models\Adjustment;
use App\Models\RestoreTask;

/**
 * 到期恢复服务：把到期的调整恢复为常规营业时间。
 *
 * 防误恢复双保险：
 *  1. 延期/提前结束的新版本批准时，旧版本的待执行任务已被取消（见 AdjustmentService）；
 *  2. 执行前再次复核：只有"调整仍是 approved 且确为链上当前版本"的任务才会执行，
 *     否则标记 skipped_stale —— 即使队列里残留旧任务，也绝不会在延期后误恢复。
 */
class RestoreService
{
    public function __construct(private PublishService $publisher) {}

    /**
     * 执行所有到期的恢复任务。
     *
     * @return array{executed: int, skipped: int}
     */
    public function runDue(): array
    {
        $tasks = RestoreTask::pending()->due(now())->with('adjustment')->orderBy('run_at')->get();

        $executed = 0;
        $skipped = 0;

        foreach ($tasks as $task) {
            $adjustment = $task->adjustment;

            $isCurrent = $adjustment
                && $adjustment->status === Adjustment::STATUS_APPROVED
                && Adjustment::currentApprovedOfChain($adjustment->root_id)?->id === $adjustment->id;

            if (! $isCurrent) {
                $task->status = RestoreTask::STATUS_SKIPPED;
                $task->executed_at = now();
                $task->result_note = '调整已被新版本取代或已失效，跳过恢复';
                $task->save();
                $skipped++;
                continue;
            }

            $this->publisher->publishRestore($adjustment->store, $adjustment);

            $adjustment->status = Adjustment::STATUS_EXPIRED;
            $adjustment->save();

            $task->status = RestoreTask::STATUS_DONE;
            $task->executed_at = now();
            $task->result_note = '已恢复常规营业时间';
            $task->save();
            $executed++;
        }

        return ['executed' => $executed, 'skipped' => $skipped];
    }
}
