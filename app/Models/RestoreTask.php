<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestoreTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DONE = 'done';
    public const STATUS_CANCELLED = 'cancelled_superseded';
    public const STATUS_SKIPPED = 'skipped_stale';

    protected $fillable = [
        'adjustment_id', 'store_id', 'version', 'run_at',
        'status', 'executed_at', 'result_note',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeDue($query, $now)
    {
        return $query->where('run_at', '<=', $now);
    }
}
