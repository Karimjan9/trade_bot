<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelPolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'evidence_end_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
