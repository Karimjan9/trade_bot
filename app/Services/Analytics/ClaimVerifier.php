<?php

namespace App\Services\Analytics;

use App\Models\ClaimCheck;
use App\Models\Signal;
use App\Models\SignalEvent;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageEvent;
use App\Services\Monitoring\SignalIssues;

class ClaimVerifier
{
    public function verify(SignalEvent $event): void
    {
        if ($event->type !== 'RESULT_CLAIM') {
            return;
        }
        $signal = Signal::with('channel')->findOrFail($event->signal_id);
        $pip = app(ChannelUnits::class)->pipSize($signal->channel, $signal->symbol);
        $window = app(PriceWindow::class)->read($signal->symbol, $signal->signal_time, $event->occurred_at);
        $entryTick = $window['ticks']->first(function ($tick) use ($signal) {
            $price = $signal->direction === 'BUY' ? $tick->ask : $tick->bid;

            return $tick->recorded_at->lte($signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds'))) && $price >= $signal->entry_min && $price <= $signal->entry_max;
        });
        $entry = $entryTick ? ($signal->direction === 'BUY' ? $entryTick->ask : $entryTick->bid) : null;
        $source = $event->source_event_id ? TelegramMessageEvent::find($event->source_event_id) : null;
        $messageId = $source ? TelegramMessage::where('channel_id', $source->channel_id)->where('telegram_message_id', $source->telegram_message_id)->value('id') : null;
        foreach (($event->payload['claims'] ?? []) as $index => $claim) {
            $status = 'DATA_GAP';
            $observed = $shortfall = null;
            if (! $pip || $claim['unit'] !== 'PIPS') {
                $status = 'UNITS_UNKNOWN';
            } elseif ($entry !== null) {
                $moves = $window['ticks']->filter(fn ($t) => $t->recorded_at->gte($entryTick->recorded_at))->map(fn ($t) => (($signal->direction === 'BUY' ? $t->bid : $t->ask) - $entry) * ($signal->direction === 'BUY' ? 1 : -1) / $pip);
                $observed = round($claim['value'] >= 0 ? max(0, $moves->max()) : min(0, $moves->min()), 3);
                $shortfall = round(max(0, abs($claim['value']) - abs($observed)), 3);
                $status = $shortfall <= 1e-6 ? 'OBSERVED_REACHED' : ($window['covered'] && $event->occurred_at->lte(now()) ? 'OBSERVED_SHORTFALL' : 'DATA_GAP');
            } else {
                $status = 'NO_OBSERVED_ENTRY';
            }
            $evidence = ['method' => 'first-fillable-quote-excursion-1', 'pip_size' => $pip, 'reference_entry' => $entry, 'reference_entry_at' => $entryTick?->recorded_at->toIso8601String(), 'window_start' => $signal->signal_time->toIso8601String(), 'window_end' => $event->occurred_at->toIso8601String(), 'tick_count' => $window['count'], 'max_gap_seconds' => $window['max_gap_seconds'], 'covered' => $window['covered'], 'claim_unit' => $claim['unit'], 'note' => 'Observed bid/ask excursion, not proof of realized profit or an uninterrupted price path. Shortfall with sparse data remains inconclusive.'];
            $fingerprint = hash('sha256', json_encode([$event->id, $index, $claim, $pip, $window['ticks']->pluck('id')->all(), $evidence]));
            $check = ClaimCheck::firstOrCreate(['fingerprint' => $fingerprint], ['signal_event_id' => $event->id, 'claim_index' => $index, 'status' => $status, 'claimed_pips' => $claim['unit'] === 'PIPS' ? $claim['value'] : null, 'observed_pips' => $observed, 'shortfall_pips' => $shortfall, 'evidence' => $evidence]);
            app(SignalIssues::class)->claim($check, $signal, $messageId);
        }
    }

    public function channel(TelegramChannel $channel): int
    {
        $count = 0;
        SignalEvent::where('type', 'RESULT_CLAIM')->whereIn('signal_id', $channel->signals()->select('id'))->orderBy('id')->lazyById(200)->each(function ($event) use (&$count) {
            $this->verify($event);
            $count++;
        });

        return $count;
    }
}
