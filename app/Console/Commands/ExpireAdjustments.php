<?php

namespace App\Console\Commands;

use App\Models\Adjustment;
use App\Models\ChannelPublish;
use App\Services\ChannelPublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 到期恢复任务：把“仍然有效（approved）且已过期”的调整标记为 expired，
 * 并向各渠道发布恢复常规营业时间。
 *
 * 关键：只查 status=approved。被延期/提前结束取代的旧版本状态是
 * superseded，永远不会被本任务捞起，因此延期后不会产生错误的恢复。
 */
class ExpireAdjustments extends Command
{
    protected $signature = 'adjustments:expire';

    protected $description = '到期的有效调整转为 expired 并向渠道发布恢复常规营业时间';

    public function handle(ChannelPublisher $publisher): int
    {
        $due = Adjustment::where('status', Adjustment::STATUS_APPROVED)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($due as $adjustment) {
            $publishes = DB::transaction(function () use ($adjustment) {
                $adjustment->update(['status' => Adjustment::STATUS_EXPIRED]);

                $created = [];
                foreach (array_keys(config('channels.channels')) as $channel) {
                    $created[] = ChannelPublish::create([
                        'adjustment_id' => $adjustment->id,
                        'channel' => $channel,
                        'kind' => ChannelPublish::KIND_RESTORE,
                        'status' => ChannelPublish::STATUS_PENDING,
                    ]);
                }

                return $created;
            });

            foreach ($publishes as $publish) {
                $publisher->attempt($publish);
            }

            $this->info("调整 #{$adjustment->id} 已到期，恢复常规营业时间");
        }

        if ($due->isEmpty()) {
            $this->info('没有到期的有效调整');
        }

        return self::SUCCESS;
    }
}
