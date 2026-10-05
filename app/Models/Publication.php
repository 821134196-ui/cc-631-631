<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Publication extends Model
{
    public const KIND_APPLY = 'apply';
    public const KIND_RESTORE = 'restore';

    protected $fillable = ['store_id', 'adjustment_id', 'kind', 'epoch', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }

    public function channelReceipts(): HasMany
    {
        return $this->hasMany(PublicationChannel::class);
    }
}
