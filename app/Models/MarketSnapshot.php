<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketSnapshot extends Model
{
    protected $guarded = [];

    public static function latestCurrent(string $symbol): ?self
    {
        return static::where('symbol', $symbol)->where('is_historical', false)->where('recorded_at', '<=', now()->addSeconds(5))->orderByDesc('recorded_at')->orderByDesc('id')->first();
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'market_open' => 'boolean', 'is_historical' => 'boolean'];
    }
}
