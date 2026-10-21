<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelStrategy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'report' => 'array', 'evidence_end_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
