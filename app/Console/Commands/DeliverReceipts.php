<?php

namespace App\Console\Commands;

use App\Services\PublishService;
use Illuminate\Console\Command;

class DeliverReceipts extends Command
{
    protected $signature = 'receipts:deliver';

    protected $description = '投递所有到期的延迟渠道回执（模拟渠道异步回执到达）';

    public function handle(PublishService $publisher): int
    {
        $count = $publisher->deliverDue();
        $this->info("已投递 {$count} 条到期回执");

        return self::SUCCESS;
    }
}
