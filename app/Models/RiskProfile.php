<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RiskProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['emergency_stop' => 'boolean'];
    }

    public static function current(bool $lock = false): self
    {
        return static::query()->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(1);
    }
}
