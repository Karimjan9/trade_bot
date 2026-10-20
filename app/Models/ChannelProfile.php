<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChannelProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metrics' => 'array', 'dna' => 'array', 'computed_at' => 'datetime'];
    }
}
