<?php

namespace App\Services\Learning;

use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use Carbon\CarbonImmutable;

class SignalTrajectory
{
    // The raw path continues after a TP/SL. It never credits post-stop movement as trade profit.
    public function update(MlObservation $observation): void
    {
        $observation->refresh();
        if (in_array($observation->path_state, ['COMPLETE', 'NO_ENTRY', 'UNITS_UNKNOWN', 'UNSUPPORTED_PLAN'])) {
            return;
        }
        $plan = $observation->plan;
        if (! isset($plan['entry_deadline'], $plan['trajectory_horizon_seconds'])) {
            $observation->update(['path_state' => 'UNSUPPORTED_PLAN']);

            return;
        }
        if (! ($plan['pip_size'] ?? null)) {
            $observation->update(['path_state' => 'UNITS_UNKNOWN']);

            return;
        }
        $start = $observation->observed_at;
        $deadline = CarbonImmutable::parse($plan['entry_deadline']);
        $upper = $deadline->addSeconds($plan['trajectory_horizon_seconds'])->min(now());
        $initial = MarketSnapshot::findOrFail($observation->snapshot_id);
        $ticks = MarketSnapshot::where('symbol', $plan['symbol'])->where('market_open', true)->whereBetween('recorded_at', [$start, $upper])
            ->where(fn ($q) => $q->where('recorded_at', '>', $start)->orWhere('id', '>', $initial->id))->orderBy('recorded_at')->orderBy('id')->limit(100001)->get()->prepend($initial);
        if ($ticks->count() > 100001) {
            $observation->update(['path_state' => 'DATA_GAP']);

            return;
        }
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $entry = $enteredAt = $end = $stop = null;
        $previous = $start;
        $gap = $favourable = $adverse = 0;
        $milestones = $checkpoints = $samples = $ids = [];
        $stopAt = null;
        foreach ($ticks as $i => $tick) {
            $at = $i === 0 ? $start : $tick->recorded_at;
            if ($end && $at->gt($end)) {
                break;
            }
            if ($entry === null && $at->gt($deadline)) {
                break;
            }
            $gap = max($gap, abs($previous->diffInSeconds($at)));
            $previous = $at;
            $ids[] = $tick->id;
            if ($entry === null) {
                $candidate = ($sign === 1 ? $tick->ask : $tick->bid) + $sign * $plan['slippage'];
                if ($candidate < $plan['entry_min'] - $plan['slippage'] || $candidate > $plan['entry_max'] + $plan['slippage']) {
                    continue;
                }
                $entry = $candidate;
                $enteredAt = $at;
                $end = $enteredAt->copy()->addSeconds($plan['trajectory_horizon_seconds']);
                $stop = isset($plan['relative_stop']) ? $entry - $sign * $plan['relative_stop'] : $plan['stop_loss'];
            }
            $price = $sign === 1 ? $tick->bid : $tick->ask;
            $pips = ($price - $entry) * $sign / $plan['pip_size'];
            $adverse = max($adverse, -$pips);
            $favourable = max($favourable, $pips);
            if ($stopAt === null && ($price - $stop) * $sign <= 0) {
                $stopAt = $at->toIso8601String();
            }
            foreach ($plan['trajectory_milestones_pips'] as $target) {
                if ($pips + 1e-8 >= $target && ! isset($milestones[$target])) {
                    $milestones[$target] = ['at' => $at->toIso8601String(), 'elapsed_seconds' => $enteredAt->diffInSeconds($at), 'adverse_before_pips' => $adverse, 'initial_stop_already_hit' => $stopAt !== null];
                }
            }
            $distance = abs($entry - $stop);
            $net = (($price - $sign * $plan['slippage'] - $entry) * $sign * $plan['value_per_unit'] - $plan['commission_per_lot']) / max(1e-12, $distance * $plan['value_per_unit'] + $plan['commission_per_lot']);
            $samples[] = ['at' => $at, 'pips' => $pips, 'net_r' => $net];
        }
        if ($entry === null) {
            $finished = now()->gte($deadline);
            $gap = max($gap, abs($previous->diffInSeconds($upper->min($deadline))));
            $observation->update(['path_state' => $finished ? ($gap <= config('trading.research.max_quote_gap_seconds') ? 'NO_ENTRY' : 'DATA_GAP') : 'TRACKING', 'path_metrics' => ['entry_observed' => false, 'max_gap_seconds' => $gap]]);

            return;
        }
        $observedEnd = $end->copy()->min(now());
        $gap = max($gap, abs($previous->diffInSeconds($observedEnd)));
        foreach ($plan['trajectory_checkpoints_seconds'] as $seconds) {
            $checkpoint = $enteredAt->copy()->addSeconds($seconds);
            if ($checkpoint->gt($observedEnd)) {
                continue;
            }
            $sample = collect($samples)->last(fn ($s) => $s['at']->lte($checkpoint));
            $checkpoints[$seconds] = $sample && $sample['at']->diffInSeconds($checkpoint) <= config('trading.research.max_quote_gap_seconds') ? ['observed_at' => $sample['at']->toIso8601String(), 'unrealized_pips' => $sample['pips'], 'hypothetical_close_net_r' => $sample['net_r'], 'initial_stop_already_hit' => $stopAt !== null && CarbonImmutable::parse($stopAt)->lte($checkpoint)] : ['data_gap' => true];
        }
        $complete = now()->gte($end);
        $covered = $gap <= config('trading.research.max_quote_gap_seconds');
        $observation->update(['path_state' => ! $covered ? 'DATA_GAP' : ($complete ? 'COMPLETE' : 'TRACKING'), 'path_end_at' => $observedEnd, 'path_metrics' => ['entry_observed' => true, 'entry' => $entry, 'entry_at' => $enteredAt->toIso8601String(), 'pip_size' => $plan['pip_size'], 'horizon_seconds' => $plan['trajectory_horizon_seconds'], 'observed_favourable_pips' => $favourable, 'observed_adverse_pips' => $adverse, 'initial_stop_hit_at' => $stopAt, 'milestones' => $milestones, 'checkpoints' => $checkpoints, 'max_gap_seconds' => $gap, 'covered' => $covered, 'tick_fingerprint' => hash('sha256', json_encode($ids)), 'kind' => 'observed_price_path_not_realized_profit']]);
    }
}
