<?php

namespace App\Jobs;

use App\Services\Trading\DecisionEngine;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconsiderSymbol implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 30;

    public int $tries = 3;

    public function __construct(public string $symbol) {}

    public function uniqueId(): string
    {
        return $this->symbol;
    }

    public function handle(): void
    {
        app(DecisionEngine::class)->evaluateSymbol($this->symbol);
    }
}
