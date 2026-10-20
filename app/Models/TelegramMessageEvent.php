<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessageEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }
}
