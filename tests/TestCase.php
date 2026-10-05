<?php

namespace Tests;

use App\Models\Store;
use App\Models\StoreSchedule;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** 建一家带基础时间表（每天 09:00-21:00）的门店 */
    protected function makeStore(array $attrs = []): Store
    {
        $store = Store::create(array_merge([
            'name' => '测试门店',
            'code' => 'T'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
        ], $attrs));

        for ($weekday = 0; $weekday <= 6; $weekday++) {
            StoreSchedule::create([
                'store_id' => $store->id,
                'weekday' => $weekday,
                'open_time' => '09:00',
                'close_time' => '21:00',
                'is_closed' => false,
            ]);
        }

        return $store->fresh();
    }
}
