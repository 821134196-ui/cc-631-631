<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = ['name', 'code', 'regular_open', 'regular_close'];

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function channelStates(): HasMany
    {
        return $this->hasMany(ChannelState::class);
    }
}
