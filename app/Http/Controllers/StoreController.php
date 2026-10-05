<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\JsonResponse;

class StoreController extends Controller
{
    /** 门店列表（含基础周时间表） */
    public function index(): JsonResponse
    {
        $stores = Store::with('schedules')->orderBy('id')->get()->map(fn (Store $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'code' => $s->code,
            'schedules' => $s->schedules->sortBy('weekday')->values()->map(fn ($r) => [
                'weekday' => $r->weekday,
                'open_time' => $r->open_time,
                'close_time' => $r->close_time,
                'is_closed' => $r->is_closed,
            ]),
        ]);

        return response()->json($stores);
    }
}
