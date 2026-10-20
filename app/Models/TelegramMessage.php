<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramMessage extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_json' => 'array', 'is_historical' => 'boolean', 'posted_at' => 'datetime', 'edited_at' => 'datetime', 'deleted_at' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }

    public function signal()
    {
        return $this->hasOne(Signal::class, 'message_id');
    }
}
