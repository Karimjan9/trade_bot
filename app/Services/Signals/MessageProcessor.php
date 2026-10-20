<?php

namespace App\Services\Signals;

use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\SignalEvent;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageEvent;
use App\Services\Analytics\ChannelAnalytics;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\PaperExecution;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class MessageProcessor
{
    public function process(int $id): void
    {
        DB::transaction(function () use ($id) {
            RiskProfile::current(true);
            $event = TelegramMessageEvent::lockForUpdate()->findOrFail($id);
            if ($event->status !== 'pending') {
                return;
            }
            $channel = $event->channel;
            if (! $channel->enabled) {
                $event->update(['status' => 'ignored', 'processed_at' => now()]);

                return;
            }
            $payload = $event->payload;
            $message = TelegramMessage::firstOrCreate(['channel_id' => $channel->id, 'telegram_message_id' => $event->telegram_message_id], [
                'reply_to_message_id' => $payload['reply_to_message_id'] ?? null, 'raw_text' => $payload['text'] ?? '', 'raw_json' => $payload['raw_json'] ?? $payload,
                'latest_text' => $payload['text'] ?? '', 'posted_at' => CarbonImmutable::parse($payload['posted_at'] ?? $event->occurred_at), 'is_historical' => $payload['is_historical'] ?? false,
            ]);
            $signal = $message->signal;
            if ($event->kind === 'delete') {
                $message->update(['deleted_at' => $event->occurred_at, 'processing_status' => 'deleted']);
                if ($signal) {
                    $signal->update(['status' => 'deleted']);
                    $this->signalEvent($signal, $event, 'MESSAGE_DELETED', []);
                }
            } else {
                $parsed = app(SignalParser::class)->parse($payload['text'] ?? '');
                DB::table('signal_parses')->insert(['message_id' => $message->id, 'source_event_id' => $event->id, 'parser_version' => SignalParser::VERSION, 'result' => json_encode($parsed, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                if ($event->kind === 'edit') {
                    // Preserve initial signal and raw text; edits never silently replace entry/SL.
                    if (! $message->edited_at || $event->occurred_at->gte($message->edited_at)) {
                        $message->update(['latest_text' => $payload['text'] ?? '', 'edited_at' => $event->occurred_at, 'processing_status' => 'review']);
                    }
                    if ($signal) {
                        $signal->update(['status' => 'review']);
                        $this->signalEvent($signal, $event, 'MESSAGE_EDITED', ['text' => $payload['text'] ?? '', 'parse' => $parsed]);
                    }
                } elseif (! $message->deleted_at && ! $message->edited_at) {
                    $message->update(['message_type' => $parsed['type'], 'processing_status' => $parsed['type'] === 'REVIEW_REQUIRED' ? 'review' : 'processed']);
                    if ($parsed['type'] === 'NEW_SIGNAL' && ! $signal) {
                        $data = $parsed['signal'];
                        $signal = Signal::create($data + ['channel_id' => $channel->id, 'message_id' => $message->id, 'confidence' => $parsed['confidence'], 'signal_time' => $message->posted_at, 'fingerprint' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'status' => $message->is_historical ? 'historical' : 'ready']);
                        $this->signalEvent($signal, $event, 'NEW_SIGNAL', $data);
                        app(DecisionEngine::class)->evaluate($signal);
                    } elseif ($parsed['type'] === 'SIGNAL_UPDATE') {
                        // Explicit reply linkage only; ambiguous updates never affect another trade.
                        $parent = $message->reply_to_message_id ? TelegramMessage::where('channel_id', $channel->id)->where('telegram_message_id', $message->reply_to_message_id)->first()?->signal : null;
                        if ($parent) {
                            foreach ($parsed['actions'] as $action) {
                                $this->signalEvent($parent, $event, $action['type'], $action);
                                if ($action['type'] === 'CANCEL_SIGNAL') {
                                    $parent->update(['status' => 'cancelled']);
                                }
                                if (! $message->is_historical && $message->posted_at->gte(now()->subSeconds(config('trading.signal_max_age_seconds'))) && $message->posted_at->lte(now()->addSeconds(5))) {
                                    app(PaperExecution::class)->apply($parent, $action);
                                }
                            }
                        } else {
                            $message->update(['processing_status' => 'unlinked_update']);
                        }
                    }
                }
            }
            $channel->update(['last_message_id' => max($channel->last_message_id, $event->telegram_message_id), 'last_seen_at' => now()]);
            $event->update(['status' => 'processed', 'processed_at' => now()]);
            app(ChannelAnalytics::class)->refresh($channel);
        }, 3);
    }

    private function signalEvent(Signal $signal, TelegramMessageEvent $event, string $type, array $payload): void
    {
        SignalEvent::create(['signal_id' => $signal->id, 'source_event_id' => $event->id, 'type' => $type, 'payload' => $payload, 'occurred_at' => $event->occurred_at]);
    }
}
