<?php

namespace App\Services\Analytics;

use App\Models\EntryExperiment;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use Carbon\CarbonImmutable;

class EntryResearch
{
    public function study(MlObservation $observation, array $variants): ?EntryExperiment
    {
        $plan = $observation->plan;
        if (! isset($plan['entry_spec'], $plan['entry_research']) || ($observation->signal?->message?->raw_json['edit_date'] ?? null)) {
            return null;
        }
        unset($plan['frozen_entry']);
        $plan['stop_loss'] = $plan['research_stop_loss'] ?? $plan['stop_loss'];
        $end = CarbonImmutable::parse($plan['entry_deadline'])->addSeconds($plan['research_horizon_seconds']);
        if ($end->gt(now())) {
            return null;
        }
        $initial = MarketSnapshot::find($observation->snapshot_id);
        if (! $initial || $initial->recorded_at->gt($observation->observed_at)) {
            return null;
        }
        $limit = config('trading.entries.max_ticks');
        $source = MarketSnapshot::where('symbol', $plan['symbol'])->where('market_open', true)->whereBetween('recorded_at', [$observation->observed_at, $end->addSeconds(config('trading.research.max_quote_gap_seconds'))])
            ->where(fn ($q) => $q->where('recorded_at', '>', $observation->observed_at)->orWhere('id', '>', $initial->id))->orderBy('recorded_at')->orderBy('id')->limit($limit + 1)->get()->prepend($initial);
        if ($source->count() > $limit) {
            return null;
        }
        $previous = $observation->observed_at;
        $ticks = $provenance = [];
        foreach ($source as $i => $tick) {
            $at = $i === 0 ? $observation->observed_at : $tick->recorded_at;
            if (abs($previous->diffInSeconds($at)) > config('trading.research.max_quote_gap_seconds')) {
                return null;
            }
            $previous = $at;
            $ticks[] = ['at' => $at, 'bid' => $tick->bid, 'ask' => $tick->ask];
            $provenance[] = [$tick->id, $tick->bid, $tick->ask];
            if ($at->gte($end)) {
                break;
            }
        }
        if ($previous->lt($end)) {
            return null;
        }
        $fingerprint = hash('sha256', json_encode([$observation->id, $plan, $variants, $provenance, hash_file('sha256', __FILE__), hash_file('sha256', base_path('app/Services/Analytics/EntryReplay.php')), hash_file('sha256', base_path('app/Services/Trading/EntryPlans.php')), hash_file('sha256', base_path('app/Services/Trading/ExitPlans.php'))], JSON_THROW_ON_ERROR));
        if ($existing = EntryExperiment::where('fingerprint', $fingerprint)->first()) {
            return $existing;
        }
        $results = [];
        foreach ($variants as $key => $parameters) {
            $result = app(EntryReplay::class)->run($plan, $ticks, $parameters, $plan['entry_research']['budget']);
            if ($result === null) {
                return null;
            }
            $results[$key] = $result;
        }

        return EntryExperiment::firstOrCreate(['fingerprint' => $fingerprint], ['observation_id' => $observation->id, 'evidence_end_at' => $previous, 'report' => ['method' => 'joint-entry-exit-1', 'kind' => 'hypothetical_not_realized_profit', 'budget' => $plan['entry_research']['budget'], 'variants' => $results, 'tick_count' => count($ticks), 'no_entry_counts_as_zero' => true]]);
    }
}
