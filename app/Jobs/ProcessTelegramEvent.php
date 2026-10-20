<?php

namespace App\Jobs;

use App\Services\Signals\MessageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessTelegramEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [2, 5, 15, 30];
    }

    public function handle(MessageProcessor $processor): void
    {
        $processor->process($this->eventId);
    }
}
