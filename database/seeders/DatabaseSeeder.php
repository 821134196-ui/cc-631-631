<?php

namespace Database\Seeders;

use App\Models\Adjustment;
use App\Models\Store;
use App\Models\StoreSchedule;
use App\Services\AdjustmentService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $stores = [
            ['name' => '晨光咖啡（人民广场店）', 'code' => 'SH001'],
            ['name' => '晨光咖啡（张江店）', 'code' => 'SH002'],
            ['name' => '晨光咖啡（虹桥店）', 'code' => 'SH003'],
        ];

        foreach ($stores as $row) {
            $store = Store::create($row);
            // 基础时间表：周一至周日 09:00-21:00，周日休息的门店演示闭店日
            for ($weekday = 0; $weekday <= 6; $weekday++) {
                StoreSchedule::create([
                    'store_id' => $store->id,
                    'weekday' => $weekday,
                    'open_time' => '09:00',
                    'close_time' => '21:00',
                    'is_closed' => $store->code === 'SH003' && $weekday === 0,
                ]);
            }
        }

        // 演示数据：一条已批准的临时停业 + 一条审核中的延长营业
        $service = app(AdjustmentService::class);
        $store = Store::where('code', 'SH001')->first();

        $approved = $service->submit($store, [
            'type' => Adjustment::TYPE_CLOSURE,
            'start_at' => now()->addDays(2)->setTime(0, 0),
            'end_at' => now()->addDays(4)->setTime(23, 59),
            'reason' => '门店设备检修，临时停业',
            'submitted_by' => '店长小王',
        ]);
        $service->approve($approved, '总部审核员', '同意，注意同步外卖平台');

        $service->submit($store, [
            'type' => Adjustment::TYPE_EXTENDED,
            'start_at' => now()->addDays(10)->setTime(0, 0),
            'end_at' => now()->addDays(12)->setTime(23, 59),
            'open_time' => '08:00',
            'close_time' => '23:00',
            'reason' => '节假日客流高峰，延长营业',
            'submitted_by' => '店长小王',
        ]);
    }
}
