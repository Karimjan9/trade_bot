<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceHeartbeat extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'seen_at' => 'datetime'];
    }
}
