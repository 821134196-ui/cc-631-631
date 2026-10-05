<?php

namespace App\Console\Commands;

use App\Services\RestoreService;
use Illuminate\Console\Command;

class RunExpiry extends Command
{
    protected $signature = 'adjustments:expire';

    protected $description = '执行到期的恢复任务（仅对仍然有效的调整生效）';

    public function handle(RestoreService $restore): int
    {
        $result = $restore->runDue();
        $this->info("恢复任务：执行 {$result['executed']} 个，跳过 {$result['skipped']} 个（已失效）");

        return self::SUCCESS;
    }
}
