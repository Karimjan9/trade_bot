<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaperTrade extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'expires_at' => 'datetime', 'management_policy' => 'array', 'entry_legs' => 'array'];
    }

    public function signal()
    {
        return $this->belongsTo(Signal::class);
    }

    public function exposureRisk(): float
    {
        if ($this->entry_legs === null) {
            return $this->risk_amount * $this->remaining_fraction;
        }

        return collect($this->entry_legs)->sum(fn ($leg) => $leg['status'] === 'PENDING' ? $leg['reserved_risk'] : ($leg['status'] === 'FILLED' ? $leg['reserved_risk'] * $this->remaining_fraction : 0));
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
