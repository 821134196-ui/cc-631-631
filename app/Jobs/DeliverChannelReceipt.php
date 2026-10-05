<?php

namespace App\Jobs;

use App\Models\ChannelPublish;
use App\Services\ReceiptHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * 模拟外卖平台的延迟异步回执：发布受理后过一段时间才回执。
 * 回执到达时是否采纳，完全由 ReceiptHandler 按“是否最新版本”判定。
 */
class DeliverChannelReceipt implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $publishId) {}

    public function handle(ReceiptHandler $receipts): void
    {
        $publish = ChannelPublish::find($this->publishId);

        if (! $publish) {
            return;
        }

        $receipts->record($publish, 'success', [
            'channel' => $publish->channel,
            'kind' => $publish->kind,
            'adjustment_version' => $publish->adjustment->version,
            'receipt_no' => strtoupper(substr(md5('async-'.$publish->id.'-'.$publish->attempts), 0, 10)),
        ], '外卖平台异步回执');
    }
}
