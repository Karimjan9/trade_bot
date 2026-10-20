<?php

namespace App\Services\Trading;

use App\Models\MarketSnapshot;
use App\Models\RiskProfile;
use App\Models\ServiceHeartbeat;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Services\Analytics\ChannelAnalytics;
use Illuminate\Support\Facades\DB;

class MarketData
{
    public function record(array $data): MarketSnapshot
    {
        return DB::transaction(function () use ($data) {
            $risk = RiskProfile::current(true);
            $last = MarketSnapshot::latestCurrent($data['symbol']);
            $quote = MarketSnapshot::create($data);
            if (! $quote->is_historical && $quote->market_open && (! $last || $quote->recorded_at->gte($last->recorded_at)) && $quote->recorded_at->gte(now()->subSeconds(config('trading.quote_max_age_seconds'))) && $quote->recorded_at->lte(now()->addSeconds(5))) {
                app(PaperExecution::class)->mark($quote);
                $equity = app(Portfolio::class)->summary()['equity'];
                if ($equity > $risk->peak_equity) {
                    $risk->update(['peak_equity' => $equity]);
                }
                ServiceHeartbeat::updateOrCreate(['service' => 'market'], ['status' => 'ONLINE', 'metadata' => ['source' => $quote->source, 'symbol' => $quote->symbol], 'seen_at' => now()]);
                foreach (TelegramChannel::all() as $channel) {
                    app(ChannelAnalytics::class)->refresh($channel);
                }
                // Retry only transient waits, within the original signal's validity window.
                $waiting = Signal::where('symbol', $quote->symbol)->where('status', 'ready')->where('signal_time', '>=', now()->subSeconds(config('trading.signal_max_age_seconds')))->whereDoesntHave('trades', fn ($q) => $q->where('mode', 'paper'))->orderBy('id')->limit(100)->get();
                foreach ($waiting as $signal) {
                    $lastDecision = $signal->decisions()->first();
                    if ($lastDecision && ($lastDecision->action === 'WAIT' || in_array('NO_MARKET_DATA', $lastDecision->reasons))) {
                        app(DecisionEngine::class)->evaluate($signal);
                    }
                }
            }

            return $quote;
        }, 3);
    }
}
