<?php

namespace App\Http\Controllers;

use App\Models\ChannelState;
use App\Models\Store;
use App\Support\Channels;
use Illuminate\Http\JsonResponse;

class ChannelStateController extends Controller
{
    /** 各渠道当前真实对外展示的内容（本地模拟的渠道端） */
    public function index(Store $store): JsonResponse
    {
        $states = ChannelState::where('store_id', $store->id)->get()->keyBy('channel');

        $data = collect(Channels::ALL)->map(function (string $channel) use ($states) {
            $state = $states->get($channel);

            return [
                'channel' => $channel,
                'label' => Channels::label($channel),
                'applied_epoch' => $state?->applied_epoch ?? 0,
                'applied_publication_id' => $state?->applied_publication_id,
                'content' => $state?->content,
                'updated_at' => $state?->updated_at?->toDateTimeString(),
            ];
        })->values();

        return response()->json($data);
    }
}
