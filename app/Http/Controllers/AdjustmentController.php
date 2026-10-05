<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Publication;
use App\Models\Store;
use App\Services\AdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdjustmentController extends Controller
{
    public function __construct(private AdjustmentService $service) {}

    /** 调整申请列表（含版本链与最新一次发布的渠道同步状态） */
    public function index(Store $store): JsonResponse
    {
        $adjustments = Adjustment::where('store_id', $store->id)
            ->orderByDesc('root_id')->orderBy('version')
            ->get();

        // 每个调整最近一次 apply 发布的各渠道回执状态
        $publications = Publication::where('store_id', $store->id)
            ->where('kind', Publication::KIND_APPLY)
            ->with('channelReceipts')
            ->get()
            ->groupBy('adjustment_id')
            ->map(fn ($group) => $group->sortByDesc('epoch')->first());

        $data = $adjustments->map(function (Adjustment $a) use ($publications) {
            $pub = $publications->get($a->id);
            $sync = [];
            if ($pub) {
                foreach ($pub->channelReceipts as $r) {
                    $sync[$r->channel] = [
                        'status' => $r->status,
                        'attempts' => $r->attempts,
                        'last_error' => $r->last_error,
                    ];
                }
            }

            return [
                'id' => $a->id,
                'root_id' => $a->root_id,
                'version' => $a->version,
                'type' => $a->type,
                'start_at' => $a->start_at->toDateTimeString(),
                'end_at' => $a->end_at->toDateTimeString(),
                'open_time' => $a->open_time,
                'close_time' => $a->close_time,
                'reason' => $a->reason,
                'status' => $a->status,
                'submitted_by' => $a->submitted_by,
                'reviewed_by' => $a->reviewed_by,
                'reviewed_at' => $a->reviewed_at?->toDateTimeString(),
                'review_note' => $a->review_note,
                'created_at' => $a->created_at->toDateTimeString(),
                'channel_sync' => $sync,
            ];
        })->values();

        return response()->json($data);
    }

    /** 门店提交新申请 */
    public function store(Request $request, Store $store): JsonResponse
    {
        $data = $this->validatePayload($request);

        $adjustment = $this->service->submit($store, $data);

        return response()->json(['id' => $adjustment->id], 201);
    }

    /** 总部批准 */
    public function approve(Request $request, Adjustment $adjustment): JsonResponse
    {
        $reviewer = $request->input('reviewed_by', '总部审核员');
        $adjustment = $this->service->approve($adjustment, $reviewer, $request->input('review_note'));

        return response()->json(['id' => $adjustment->id, 'status' => $adjustment->status]);
    }

    /** 总部驳回 */
    public function reject(Request $request, Adjustment $adjustment): JsonResponse
    {
        $reviewer = $request->input('reviewed_by', '总部审核员');
        $adjustment = $this->service->reject($adjustment, $reviewer, $request->input('review_note'));

        return response()->json(['id' => $adjustment->id, 'status' => $adjustment->status]);
    }

    /** 延期 / 提前结束：基于当前批准版本生成新版本（pending） */
    public function revise(Request $request, Adjustment $adjustment): JsonResponse
    {
        $data = $this->validatePayload($request, $adjustment);

        $next = $this->service->revise($adjustment, $data);

        return response()->json(['id' => $next->id, 'version' => $next->version], 201);
    }

    private function validatePayload(Request $request, ?Adjustment $base = null): array
    {
        $rules = [
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'reason' => ['nullable', 'string', 'max:200'],
            'submitted_by' => ['required', 'string', 'max:50'],
            'open_time' => ['nullable', 'date_format:H:i'],
            'close_time' => ['nullable', 'date_format:H:i'],
        ];

        if (! $base) {
            $rules['type'] = ['required', 'in:closure,extended'];
            $rules['reason'] = ['required', 'string', 'max:200'];
        }

        $data = $request->validate($rules);

        $type = $base?->type ?? $data['type'];
        if ($type === 'extended') {
            $open = $data['open_time'] ?? $base?->open_time;
            $close = $data['close_time'] ?? $base?->close_time;
            if (! $open || ! $close) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'open_time' => '延长营业必须填写对外营业时间',
                ]);
            }
            if ($close <= $open) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'close_time' => '打烊时间必须晚于开门时间',
                ]);
            }
            $data['open_time'] = $open;
            $data['close_time'] = $close;
        }

        return $data;
    }
}
