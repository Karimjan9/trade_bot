<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelMlModel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['artifact' => 'array', 'report' => 'array', 'data_until' => 'datetime', 'activated_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
