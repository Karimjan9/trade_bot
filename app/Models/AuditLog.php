<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(string $action, ?string $subject = null, ?array $before = null, ?array $after = null): self
    {
        return static::create(['user_id' => auth()->id(), 'action' => $action, 'subject' => $subject, 'before' => $before, 'after' => $after]);
    }
}
