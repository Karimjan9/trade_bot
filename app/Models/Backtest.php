<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backtest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'results' => 'array'];
    }
}
