<?php

namespace App\Http\Controllers;

use App\Models\ChannelPublish;
use App\Models\Store;
use App\Services\ChannelPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ChannelStatusController extends Controller
{
    public function __construct(private ChannelPublisher $publisher) {}

    /**
     * 渠道状态页：每个调整版本 × 渠道的发布状态 + 全部回执日志。
     * 与“批准结果”分开呈现，门店能看清每个渠道是否同步成功。
     */
    public function index(Request $request): JsonResponse
    {
        $query = ChannelPublish::with(['adjustment.store', 'receipts' => fn ($q) => $q->orderByDesc('id')])
            ->orderByDesc('id');

        if ($request->filled('store_id')) {
            $query->whereHas('adjustment', fn ($q) => $q->where('store_id', $request->integer('store_id')));
        }

        $publishes = $query->limit(100)->get()->map(fn (ChannelPublish $p) => [
            'id' => $p->id,
            'channel' => $p->channel,
            'channel_name' => config("channels.channels.{$p->channel}.name", $p->channel),
            'kind' => $p->kind,
            'status' => $p->status,
            'attempts' => $p->attempts,
            'last_error' => $p->last_error,
            'published_at' => $p->published_at?->toDateTimeString(),
            'adjustment' => [
                'id' => $p->adjustment->id,
                'version' => $p->adjustment->version,
                'type' => $p->adjustment->type,
                'status' => $p->adjustment->status,
                'starts_at' => $p->adjustment->starts_at?->toDateTimeString(),
                'ends_at' => $p->adjustment->ends_at?->toDateTimeString(),
                'store_name' => $p->adjustment->store->name,
            ],
            'receipts' => $p->receipts->map(fn ($r) => [
                'id' => $r->id,
                'attempt' => $r->attempt,
                'result' => $r->result,
                'accepted' => $r->accepted,
                'note' => $r->note,
                'payload' => $r->payload,
                'created_at' => $r->created_at?->toDateTimeString(),
            ])->values(),
        ]);

        return response()->json($publishes);
    }

    /** 各渠道当前对外显示的内容（channel_states 直读） */
    public function channelStates(Request $request): JsonResponse
    {
        $stores = Store::with('channelStates.adjustment')->orderBy('id')->get();

        return response()->json($stores->map(fn (Store $s) => [
            'store_id' => $s->id,
            'store_name' => $s->name,
            'regular' => "{$s->regular_open}-{$s->regular_close}",
            'channels' => collect(config('channels.channels'))->map(function ($cfg, $key) use ($s) {
                $state = $s->channelStates->firstWhere('channel', $key);

                return [
                    'channel' => $key,
                    'channel_name' => $cfg['name'],
                    'displaying' => $state?->payload ?? [
                        'type' => 'regular',
                        'label' => "正常营业 {$s->regular_open}-{$s->regular_close}",
                    ],
                    'adjustment_version' => $state?->adjustment?->version,
                    'synced_at' => $state?->synced_at?->toDateTimeString(),
                ];
            })->values(),
        ]));
    }

    /** 失败渠道单独重试 */
    public function retry(ChannelPublish $publish): JsonResponse
    {
        try {
            $publish = $this->publisher->retry($publish);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $publish->id,
            'status' => $publish->status,
            'attempts' => $publish->attempts,
            'last_error' => $publish->last_error,
        ]);
    }
}
