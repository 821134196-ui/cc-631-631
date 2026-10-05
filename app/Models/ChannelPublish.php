<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelPublish extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STALE = 'stale'; // 已被更新的发布取代，回执不再生效

    public const KIND_APPLY = 'apply';
    public const KIND_RESTORE = 'restore';

    protected $fillable = [
        'adjustment_id', 'channel', 'kind', 'status',
        'attempts', 'last_error', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PublishReceipt::class);
    }
}
