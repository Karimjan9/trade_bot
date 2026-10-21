<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringIssueEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'created_at' => 'datetime'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
