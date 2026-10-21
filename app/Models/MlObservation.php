<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MlObservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['features' => 'array', 'context' => 'array', 'plan' => 'array', 'outcome' => 'array', 'path_metrics' => 'array', 'observed_at' => 'datetime', 'label_end_at' => 'datetime', 'path_end_at' => 'datetime'];
    }

    public function signal()
    {
        return $this->belongsTo(Signal::class);
    }
}
