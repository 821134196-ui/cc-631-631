<?php

namespace App\Http\Controllers;

use App\Models\ChannelDirective;
use App\Services\PublishService;
use App\Services\RestoreService;
use App\Support\Channels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 本地模拟控制：故障/延迟注入、投递到期回执、运行到期恢复检查。
 * 用于演示与手工验证"部分失败单独补发 / 延期后旧恢复不生效 / 晚到回执不覆盖"。
 */
class SimulationController extends Controller
{
    /** 注入故障或延迟：fail_once | fail_always | delay_once；clear 清除该渠道指令 */
    public function directive(Request $request): JsonResponse
    {
        $data = $request->validate([
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'channel' => ['required', 'in:'.implode(',', Channels::ALL)],
            'mode' => ['required', 'in:fail_once,fail_always,delay_once,clear'],
            'delay_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'message' => ['nullable', 'string', 'max:200'],
        ]);

        if ($data['mode'] === 'clear') {
            ChannelDirective::where('channel', $data['channel'])
                ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $data['store_id'] ?? 0))
                ->delete();

            return response()->json(['cleared' => true]);
        }

        $directive = ChannelDirective::create([
            'store_id' => $data['store_id'] ?? null,
            'channel' => $data['channel'],
            'mode' => $data['mode'],
            'delay_seconds' => $data['delay_seconds'] ?? 60,
            'message' => $data['message'] ?? null,
        ]);

        return response()->json(['id' => $directive->id], 201);
    }

    /** 投递所有到期的延迟回执 */
    public function deliverReceipts(PublishService $publisher): JsonResponse
    {
        return response()->json(['delivered' => $publisher->deliverDue()]);
    }

    /** 运行到期恢复检查 */
    public function runExpiry(RestoreService $restore): JsonResponse
    {
        return response()->json($restore->runDue());
    }
}
