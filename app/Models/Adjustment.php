<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adjustment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';

    public const TYPE_CLOSURE = 'closure';     // 临时停业
    public const TYPE_EXTENDED = 'extended';   // 延长营业
    public const TYPE_MODIFIED = 'modified';   // 自定义时段（含提前结束）

    protected $fillable = [
        'store_id', 'group_id', 'version', 'supersedes_id', 'type',
        'starts_at', 'ends_at', 'open_time', 'close_time', 'reason',
        'status', 'submitted_by', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function publishes(): HasMany
    {
        return $this->hasMany(ChannelPublish::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(PublishReceipt::class);
    }

    /** 是否为该调整组内的最新版本 */
    public function isLatestVersion(): bool
    {
        return ! static::where('group_id', $this->group_id)
            ->where('version', '>', $this->version)
            ->exists();
    }

    /** 该版本当前是否对外生效（已批准且仍在时间窗内） */
    public function isEffectiveNow(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->starts_at->lte(now())
            && $this->ends_at->gt(now());
    }
}
