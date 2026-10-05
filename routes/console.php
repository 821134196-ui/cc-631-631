<?php

use Illuminate\Support\Facades\Schedule;

// 每分钟检查一次：仍有效但已到期的调整 → 恢复常规营业时间
Schedule::command('adjustments:expire')->everyMinute()->withoutOverlapping();
