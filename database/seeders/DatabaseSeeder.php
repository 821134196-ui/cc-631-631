<?php

namespace Database\Seeders;

use App\Models\Store;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Store::updateOrCreate(
            ['code' => 'SH001'],
            ['name' => '上海静安寺店', 'regular_open' => '09:00', 'regular_close' => '22:00'],
        );
        Store::updateOrCreate(
            ['code' => 'SH002'],
            ['name' => '上海徐家汇店', 'regular_open' => '08:30', 'regular_close' => '21:30'],
        );
        Store::updateOrCreate(
            ['code' => 'BJ001'],
            ['name' => '北京三里屯店', 'regular_open' => '10:00', 'regular_close' => '23:00'],
        );
    }
}
