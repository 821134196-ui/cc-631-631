<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublishReceipt extends Model
{
    public const UPDATED_AT = null; // 只追加，不更新

    protected $fillable = [
        'channel_publish_id', 'adjustment_id', 'channel', 'version',
        'attempt', 'result', 'payload', 'accepted', 'note',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'accepted' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function publish(): BelongsTo
    {
        return $this->belongsTo(ChannelPublish::class, 'channel_publish_id');
    }

    public function adjustment(): BelongsTo
    {
        return $this->belongsTo(Adjustment::class);
    }
}
