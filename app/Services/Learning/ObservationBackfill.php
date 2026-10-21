<?php

namespace App\Services\Learning;

use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Services\Trading\ChannelPolicyResolver;

class ObservationBackfill
{
    public function run(TelegramChannel $channel): int
    {
        $count = 0;
        $signals = Signal::where('channel_id', $channel->id)->whereNotIn('id', MlObservation::select('signal_id'))
            ->with('message', 'channel')->orderByDesc('signal_time')->limit(config('trading.ml.max_rows'))->get()->reverse();
        foreach ($signals as $signal) {
            if (isset($signal->message->raw_json['edit_date'])) {
                continue;
            }
            $quote = MarketSnapshot::where('symbol', $signal->symbol)->where('market_open', true)
                ->whereBetween('recorded_at', [$signal->signal_time, $signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds'))])
                ->orderBy('recorded_at')->orderBy('id')->first();
            if (! $quote || $quote->recorded_at->gt(now())) {
                continue;
            }
            // A policy trained today may never supply historical missing levels.
            $plan = app(ChannelPolicyResolver::class)->resolve($signal, $quote, $quote->recorded_at);
            if ($plan['reasons']) {
                continue;
            }
            if (app(ObservationRecorder::class)->capture($signal, $plan['signal'], $quote, $quote->recorded_at, $plan['management'], 'historical_replay')) {
                $count++;
            }
        }

        return $count;
    }
}
