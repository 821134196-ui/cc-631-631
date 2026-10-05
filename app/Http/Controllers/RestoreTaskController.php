<?php

namespace App\Http\Controllers;

use App\Models\RestoreTask;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class RestoreTaskController extends Controller
{
    /** 到期恢复任务列表（展示延期后旧任务被取消/跳过） */
    public function index(Store $store): JsonResponse
    {
        $tasks = RestoreTask::where('store_id', $store->id)
            ->with('adjustment:id,version,type,status')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (RestoreTask $t) => [
                'id' => $t->id,
                'adjustment_id' => $t->adjustment_id,
                'adjustment_version' => $t->adjustment?->version,
                'adjustment_status' => $t->adjustment?->status,
                'version' => $t->version,
                'run_at' => $t->run_at->toDateTimeString(),
                'status' => $t->status,
                'executed_at' => $t->executed_at?->toDateTimeString(),
                'result_note' => $t->result_note,
            ]);

        return response()->json($tasks);
    }
}
