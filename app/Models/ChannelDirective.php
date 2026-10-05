<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelDirective extends Model
{
    public const MODE_FAIL_ONCE = 'fail_once';
    public const MODE_FAIL_ALWAYS = 'fail_always';
    public const MODE_DELAY_ONCE = 'delay_once';

    protected $fillable = [
        'store_id', 'channel', 'mode', 'delay_seconds', 'message', 'consumed',
    ];

    protected $casts = ['consumed' => 'boolean'];

    /** 取一条尚未消费、适用于该门店与渠道的指令 */
    public static function activeFor(int $storeId, string $channel): ?self
    {
        return static::where('consumed', false)
            ->where('channel', $channel)
            ->where(fn ($q) => $q->whereNull('store_id')->orWhere('store_id', $storeId))
            ->orderBy('id')
            ->first();
    }
}
