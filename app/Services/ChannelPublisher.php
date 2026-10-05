<?php

namespace App\Services;

use App\Jobs\DeliverChannelReceipt;
use App\Models\ChannelPublish;
use InvalidArgumentException;

/**
 * 渠道发布模拟器：按 config/channels.php 中每个渠道的模式
 * 产生同步成功 / 首次失败 / 延迟异步回执三种行为。
 */
class ChannelPublisher
{
    public function __construct(private ReceiptHandler $receipts) {}

    /** 对一条发布记录发起一次尝试（重试也是调用这里） */
    public function attempt(ChannelPublish $publish): void
    {
        $publish->update([
            'attempts' => $publish->attempts + 1,
            'status' => ChannelPublish::STATUS_PENDING,
            'last_error' => null,
        ]);

        $mode = config("channels.channels.{$publish->channel}.mode")
            ?? throw new InvalidArgumentException("未知渠道: {$publish->channel}");

        match ($mode) {
            'sync_success' => $this->receipts->record(
                $publish, 'success', $this->receiptPayload($publish), '渠道同步返回成功'
            ),
            'fail_first_attempt' => $publish->attempts === 1
                ? $this->receipts->record($publish, 'failed', [], '模拟故障：店内屏设备离线')
                : $this->receipts->record($publish, 'success', $this->receiptPayload($publish), '重试后同步成功'),
            'delayed_receipt' => DeliverChannelReceipt::dispatch($publish->id)
                ->delay(now()->addSeconds($this->delaySeconds($publish))),
            default => throw new InvalidArgumentException("未知渠道模式: {$mode}"),
        };
    }

    /** 失败渠道单独重试。仅当该发布仍是当前有效意图时允许。 */
    public function retry(ChannelPublish $publish): ChannelPublish
    {
        if ($publish->status !== ChannelPublish::STATUS_FAILED) {
            throw new InvalidArgumentException('只有失败的发布才能重试');
        }
        if (! $this->receipts->isActionable($publish)) {
            throw new InvalidArgumentException('该发布已被更新的版本取代，不能重试旧版本');
        }

        $this->attempt($publish);

        return $publish->refresh();
    }

    private function delaySeconds(ChannelPublish $publish): int
    {
        return (int) config("channels.channels.{$publish->channel}.delay_seconds", 8);
    }

    /** 模拟渠道回执原文 */
    private function receiptPayload(ChannelPublish $publish): array
    {
        return [
            'channel' => $publish->channel,
            'kind' => $publish->kind,
            'adjustment_version' => $publish->adjustment->version,
            'echo' => $this->receipts->displayPayload($publish),
            'receipt_no' => strtoupper(substr(md5($publish->id.'-'.$publish->attempts), 0, 10)),
        ];
    }
}
