<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TradeEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }
}
