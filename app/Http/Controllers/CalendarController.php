<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Services\EffectiveHoursService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarController extends Controller
{
    public function __construct(private EffectiveHoursService $hours) {}

    /** 门店日历：当前实际对外生效的营业时间（草稿不进日历，单独返回） */
    public function show(Request $request, Store $store): JsonResponse
    {
        $from = Carbon::parse($request->query('from', now()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', $from->copy()->addDays(34)->toDateString()))->startOfDay();

        if ($to->diffInDays($from) > 92) {
            $to = $from->copy()->addDays(92);
        }

        return response()->json($this->hours->forRange($store, $from, $to));
    }
}
