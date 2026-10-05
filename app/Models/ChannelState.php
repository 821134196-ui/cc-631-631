<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelState extends Model
{
    protected $fillable = [
        'store_id', 'channel', 'applied_epoch', 'applied_publication_id', 'content',
    ];

    protected $casts = ['content' => 'array'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
