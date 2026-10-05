<?php

namespace App\Http\Controllers;

use App\Models\Publication;
use App\Models\PublicationChannel;
use App\Models\Store;
use App\Services\PublishService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PublicationController extends Controller
{
    public function __construct(private PublishService $publisher) {}

    /** 发布记录（含每次发布在各渠道的回执） */
    public function index(Store $store): JsonResponse
    {
        $publications = Publication::where('store_id', $store->id)
            ->with(['channelReceipts', 'adjustment:id,version,type'])
            ->orderByDesc('epoch')
            ->limit(100)
            ->get()
            ->map(fn (Publication $p) => [
                'id' => $p->id,
                'epoch' => $p->epoch,
                'kind' => $p->kind,
                'summary' => $p->payload['summary'] ?? '',
                'adjustment' => $p->adjustment ? [
                    'id' => $p->adjustment->id,
                    'version' => $p->adjustment->version,
                    'type' => $p->adjustment->type,
                ] : null,
                'created_at' => $p->created_at->toDateTimeString(),
                'receipts' => $p->channelReceipts->map(fn (PublicationChannel $r) => [
                    'id' => $r->id,
                    'channel' => $r->channel,
                    'status' => $r->status,
                    'attempts' => $r->attempts,
                    'last_error' => $r->last_error,
                    'deliver_after' => $r->deliver_after?->toDateTimeString(),
                    'delivered_at' => $r->delivered_at?->toDateTimeString(),
                ])->values(),
            ]);

        return response()->json($publications);
    }

    /** 失败渠道单独重试 */
    public function retry(PublicationChannel $receipt): JsonResponse
    {
        try {
            $receipt = $this->publisher->retry($receipt);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id' => $receipt->id,
            'status' => $receipt->status,
            'attempts' => $receipt->attempts,
            'last_error' => $receipt->last_error,
        ]);
    }
}
