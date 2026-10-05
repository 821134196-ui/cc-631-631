<?php

namespace App\Http\Controllers;

use App\Models\Adjustment;
use App\Models\Store;
use App\Services\AdjustmentService;
use App\Services\EffectiveHoursResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AdjustmentController extends Controller
{
    public function __construct(
        private AdjustmentService $service,
        private EffectiveHoursResolver $resolver,
    ) {}

    /** 门店列表（含各渠道当前状态） */
    public function stores(): JsonResponse
    {
        $stores = Store::with('channelStates')->orderBy('id')->get()->map(fn (Store $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'regular_open' => $s->regular_open,
            'regular_close' => $s->regular_close,
            'channel_states' => $s->channelStates->map(fn ($cs) => [
                'channel' => $cs->channel,
                'adjustment_id' => $cs->adjustment_id,
                'payload' => $cs->payload,
                'synced_at' => $cs->synced_at,
            ])->values(),
        ]);

        return response()->json($stores);
    }

    /** 门店日历：每天实际对外生效的时间（仅已批准）+ 审批中草稿（单独标注） */
    public function calendar(Store $store, Request $request): JsonResponse
    {
        $from = CarbonImmutable::parse($request->query('from', now()->toDateString()));
        $to = CarbonImmutable::parse($request->query('to', $from->addDays(13)->toDateString()));

        $effective = $this->resolver->forRange($store, $from, $to);

        $pending = Adjustment::where('store_id', $store->id)
            ->where('status', Adjustment::STATUS_PENDING)
            ->where('starts_at', '<', $to->addDay()->startOfDay())
            ->where('ends_at', '>', $from->startOfDay())
            ->get(['id', 'group_id', 'version', 'type', 'starts_at', 'ends_at', 'open_time', 'close_time']);

        return response()->json([
            'store' => $store->only(['id', 'name', 'regular_open', 'regular_close']),
            'days' => collect($effective)->map(fn ($info, $date) => ['date' => $date] + $info)->values(),
            'pending_drafts' => $pending,
        ]);
    }

    /** 调整记录：按组聚合的版本历史（批准结果） */
    public function index(Store $store): JsonResponse
    {
        $adjustments = Adjustment::where('store_id', $store->id)
            ->with(['publishes' => fn ($q) => $q->orderBy('id')])
            ->orderBy('group_id')
            ->orderBy('version')
            ->get();

        $groups = $adjustments->groupBy('group_id')->map(fn ($versions) => [
            'group_id' => $versions->first()->group_id,
            'versions' => $versions->map(fn (Adjustment $a) => $this->serialize($a))->values(),
        ])->values();

        return response()->json($groups);
    }

    /** 门店提交新调整申请 */
    public function store(Store $store, Request $request): JsonResponse
    {
        $data = $this->validateAdjustment($request);
        $data['submitted_by'] = $request->input('submitted_by', '门店店长');

        $adjustment = $this->service->submit($store, $data);

        return response()->json($this->serialize($adjustment), 201);
    }

    /** 延期 / 提前结束 / 改时段：基于当前版本生成新版本 */
    public function revise(Adjustment $adjustment, Request $request): JsonResponse
    {
        $data = $this->validateAdjustment($request, partial: true);

        try {
            $new = $this->service->revise($adjustment, $data);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($new), 201);
    }

    public function approve(Adjustment $adjustment, Request $request): JsonResponse
    {
        try {
            $approved = $this->service->approve(
                $adjustment,
                $request->input('reviewer', '总部运营'),
                $request->input('note'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($approved->load('publishes')));
    }

    public function reject(Adjustment $adjustment, Request $request): JsonResponse
    {
        try {
            $rejected = $this->service->reject(
                $adjustment,
                $request->input('reviewer', '总部运营'),
                $request->input('note'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->serialize($rejected));
    }

    private function validateAdjustment(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => [$required, 'in:closure,extended,modified'],
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'open_time' => ['nullable', 'date_format:H:i', 'required_if:type,extended,modified'],
            'close_time' => ['nullable', 'date_format:H:i', 'required_if:type,extended,modified'],
            'reason' => ['nullable', 'string', 'max:500'],
            'submitted_by' => ['nullable', 'string', 'max:50'],
        ]);
    }

    private function serialize(Adjustment $a): array
    {
        return [
            'id' => $a->id,
            'store_id' => $a->store_id,
            'group_id' => $a->group_id,
            'version' => $a->version,
            'supersedes_id' => $a->supersedes_id,
            'type' => $a->type,
            'starts_at' => $a->starts_at?->toDateTimeString(),
            'ends_at' => $a->ends_at?->toDateTimeString(),
            'open_time' => $a->open_time,
            'close_time' => $a->close_time,
            'reason' => $a->reason,
            'status' => $a->status,
            'submitted_by' => $a->submitted_by,
            'reviewed_by' => $a->reviewed_by,
            'reviewed_at' => $a->reviewed_at?->toDateTimeString(),
            'review_note' => $a->review_note,
            'publishes' => $a->relationLoaded('publishes')
                ? $a->publishes->map(fn ($p) => [
                    'id' => $p->id,
                    'channel' => $p->channel,
                    'kind' => $p->kind,
                    'status' => $p->status,
                    'attempts' => $p->attempts,
                    'last_error' => $p->last_error,
                    'published_at' => $p->published_at?->toDateTimeString(),
                ])->values()
                : null,
        ];
    }
}
