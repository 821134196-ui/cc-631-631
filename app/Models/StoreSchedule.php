<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoreSchedule extends Model
{
    protected $fillable = ['store_id', 'weekday', 'open_time', 'close_time', 'is_closed'];

    protected $casts = ['is_closed' => 'boolean'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
