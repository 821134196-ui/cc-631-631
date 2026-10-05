<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Adjustment extends Model
{
    public const TYPE_CLOSURE = 'closure';   // 临时停业
    public const TYPE_EXTENDED = 'extended'; // 延长营业

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'store_id', 'root_id', 'version', 'type',
        'start_at', 'end_at', 'open_time', 'close_time',
        'reason', 'status', 'submitted_by',
        'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function restoreTasks(): HasMany
    {
        return $this->hasMany(RestoreTask::class);
    }

    /** 同一版本链上的所有版本（含自己），按版本号排序 */
    public function chain()
    {
        return static::where('root_id', $this->root_id)->orderBy('version')->get();
    }

    /** 链上当前已批准（生效中）的版本 */
    public static function currentApprovedOfChain(int $rootId): ?self
    {
        return static::where('root_id', $rootId)
            ->where('status', self::STATUS_APPROVED)
            ->orderByDesc('version')
            ->first();
    }
}
