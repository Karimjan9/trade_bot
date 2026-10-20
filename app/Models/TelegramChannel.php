<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TelegramChannel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'learning_eligible' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class, 'channel_id');
    }

    public function signals(): HasMany
    {
        return $this->hasMany(Signal::class, 'channel_id');
    }

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class, 'channel_id');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(ChannelProfile::class, 'channel_id');
    }
}
