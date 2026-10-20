<?php

namespace App\Console\Commands;

use App\Jobs\ProcessTelegramEvent;
use App\Models\ServiceHeartbeat;
use App\Models\TelegramMessageEvent;
use Illuminate\Console\Command;

class RecoverEvents extends Command
{
    protected $signature = 'platform:recover-events';

    protected $description = 'Requeue durable pending Telegram events after worker or broker interruptions';

    public function handle(): int
    {
        $count = 0;
        TelegramMessageEvent::where('status', 'pending')->where('created_at', '<', now()->subMinute())->orderBy('id')->limit(500)->get()->each(function ($event) use (&$count) {
            ProcessTelegramEvent::dispatch($event->id);
            $count++;
        });
        ServiceHeartbeat::updateOrCreate(['service' => 'scheduler'], ['status' => 'ONLINE', 'metadata' => ['requeued' => $count], 'seen_at' => now()]);
        $this->info($count.' pending events requeued.');

        return self::SUCCESS;
    }
}
