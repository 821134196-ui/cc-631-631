<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Store extends Model
{
    protected $fillable = ['name', 'code'];

    public function schedules(): HasMany
    {
        return $this->hasMany(StoreSchedule::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(Adjustment::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(Publication::class);
    }

    public function channelStates(): HasMany
    {
        return $this->hasMany(ChannelState::class);
    }

    public function restoreTasks(): HasMany
    {
        return $this->hasMany(RestoreTask::class);
    }
}
