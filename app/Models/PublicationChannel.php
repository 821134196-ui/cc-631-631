<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationChannel extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_STALE = 'stale_ignored';

    protected $fillable = [
        'publication_id', 'channel', 'status', 'attempts',
        'last_error', 'deliver_after', 'delivered_at',
    ];

    protected $casts = [
        'deliver_after' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }
}
