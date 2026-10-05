<?php

use Illuminate\Support\Facades\Schedule;

// 常驻运行时（php artisan schedule:work）每分钟自动：
// 1. 投递到期的渠道延迟回执；2. 执行到期的恢复任务（仅对仍有效的调整生效）。
Schedule::command('receipts:deliver')->everyMinute();
Schedule::command('adjustments:expire')->everyMinute();
