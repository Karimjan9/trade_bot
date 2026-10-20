<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function signal()
    {
        return $this->belongsTo(Signal::class);
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }

    public function events()
    {
        return $this->hasMany(TradeEvent::class, 'trade_id')->orderBy('id');
    }
}
