<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Signal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['take_profits' => 'array', 'signal_time' => 'datetime'];
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }

    public function message()
    {
        return $this->belongsTo(TelegramMessage::class, 'message_id');
    }

    public function events()
    {
        return $this->hasMany(SignalEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function decisions()
    {
        return $this->hasMany(AgentDecision::class)->latest();
    }

    public function trades()
    {
        return $this->hasMany(PaperTrade::class);
    }
}
