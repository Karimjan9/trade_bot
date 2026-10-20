<?php

namespace App\Services\Signals;

use App\Jobs\ProcessTelegramEvent;
use App\Models\TelegramChannel;
use App\Models\TelegramMessageEvent;
use Carbon\CarbonImmutable;

class EventIngestor
{
    public function ingest(TelegramChannel $channel, array $data): TelegramMessageEvent
    {
        $event = TelegramMessageEvent::firstOrCreate(['event_id' => $data['event_id']], [
            'channel_id' => $channel->id, 'telegram_message_id' => $data['message_id'], 'kind' => $data['kind'],
            'payload' => $data, 'occurred_at' => CarbonImmutable::parse($data['occurred_at']),
        ]);
        abort_if($event->channel_id !== $channel->id || $event->telegram_message_id != $data['message_id'] || $event->kind !== $data['kind'] || $event->payload != $data, 409, 'Event ID payload conflict.');
        if ($event->status === 'pending') {
            ProcessTelegramEvent::dispatch($event->id)->afterCommit();
        }

        return $event;
    }
}
