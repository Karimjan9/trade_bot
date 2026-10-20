<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentDecision extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reasons' => 'array', 'context' => 'array'];
    }

    public function signal()
    {
        return $this->belongsTo(Signal::class);
    }
}
