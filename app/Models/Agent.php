<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }
}
