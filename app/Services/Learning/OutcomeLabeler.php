<?php

namespace App\Services\Learning;

use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Services\Analytics\EntryReplay;
use App\Services\Trading\ProfitLock;
use Carbon\CarbonImmutable;

class OutcomeLabeler
{
    // Counterfactual labels follow the frozen plan, even if no account trade was opened.
    // Provider reports/edits and actual trade selection never supply a training label.
    public function label(MlObservation $observation): void
    {
        $observation->refresh();
        if (! in_array($observation->state, ['PENDING', 'DATA_GAP'])) {
            return;
        }
        $plan = $observation->plan;
        $start = $observation->observed_at;
        $deadline = CarbonImmutable::parse($plan['entry_deadline']);
        $end = $deadline->addSeconds($plan['horizon_seconds'] + config('trading.research.max_quote_gap_seconds'))->min(now());
        if ($end->lt($start)) {
            return;
        }
        $initial = MarketSnapshot::findOrFail($observation->snapshot_id);
        $ticks = MarketSnapshot::where('symbol', $plan['symbol'])->where('market_open', true)
            ->where('recorded_at', '>=', $start)->where('recorded_at', '<=', $end)
            ->where(fn ($q) => $q->where('recorded_at', '>', $start)->orWhere('id', '>', $initial->id))
            ->orderBy('recorded_at')->orderBy('id')->limit(100001)->get()->prepend($initial);
        if ($ticks->count() > 100001) {
            $observation->update(['state' => 'DATA_GAP']);

            return;
        }
        if (isset($plan['frozen_entry'])) {
            $fullEnd = $deadline->addSeconds($plan['horizon_seconds']);
            if (now()->lt($fullEnd)) {
                return;
            }
            $previous = $start;
            $gap = 0;
            $rows = [];
            foreach ($ticks as $i => $tick) {
                $at = $i === 0 ? $start : $tick->recorded_at;
                $gap = max($gap, abs($previous->diffInSeconds($at)));
                $previous = $at;
                $rows[] = ['at' => $at, 'bid' => $tick->bid, 'ask' => $tick->ask];
                if ($at->gte($fullEnd)) {
                    break;
                }
            }
            if ($gap > config('trading.research.max_quote_gap_seconds') || $previous->lt($fullEnd)) {
                $observation->update(['state' => 'DATA_GAP']);

                return;
            }
            $outcome = app(EntryReplay::class)->run($plan, $rows, $plan['management'], $plan['entry_budget']);
            if ($outcome) {
                $observation->update(['state' => $outcome['entry'] === null ? 'NO_ENTRY' : 'COMPLETE', 'label_end_at' => isset($outcome['end_at']) ? CarbonImmutable::parse($outcome['end_at']) : $deadline, 'outcome' => $outcome + ['success' => $outcome['net_r'] > 0, 'kind' => 'counterfactual_frozen_entry_plan', 'includes_costs' => true]]);
            }

            return;
        }
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $previous = $start;
        $gap = 0;
        $entry = $stop = $risk = $enteredAt = null;
        $initialDistance = null;
        $mfe = $mae = 0;
        $banked = 0;
        $remaining = 1.0;
        $saved = false;
        $ids = [];
        foreach ($ticks as $i => $tick) {
            $at = $i === 0 ? $start : $tick->recorded_at;
            $gap = max($gap, abs($previous->diffInSeconds($at)));
            $previous = $at;
            $ids[] = $tick->id;
            if ($entry === null) {
                if ($at->gt($deadline)) {
                    break;
                }
                $candidate = ($sign === 1 ? $tick->ask : $tick->bid) + $sign * $plan['slippage'];
                if ($candidate < $plan['entry_min'] - $plan['slippage'] || $candidate > $plan['entry_max'] + $plan['slippage']) {
                    continue;
                }
                $entry = $candidate;
                $stop = isset($plan['relative_stop']) ? $entry - $sign * $plan['relative_stop'] : $plan['stop_loss'];
                if (($entry - $stop) * $sign <= 0 || ($plan['take_profit'] !== null && ($plan['take_profit'] - $entry) * $sign <= 0)) {
                    $observation->update(['state' => 'INVALID_PLAN']);

                    return;
                }
                $risk = abs($entry - $stop) * $plan['value_per_unit'] + $plan['commission_per_lot'];
                $initialDistance = abs($entry - $stop);
                $enteredAt = $at;
            }
            $price = $sign === 1 ? $tick->bid : $tick->ask;
            $move = ($price - $entry) * $sign / $initialDistance;
            $mfe = max($mfe, $move);
            $mae = min($mae, $move);
            $stopped = ($price - $stop) * $sign <= 0;
            $target = $plan['take_profit'] !== null && ($price - $plan['take_profit']) * $sign >= 0;
            $timed = $at->gte($enteredAt->copy()->addSeconds($plan['horizon_seconds']));
            if ($stopped || $target || $timed) {
                $exit = $target && ! $stopped ? $plan['take_profit'] : $price - $sign * $plan['slippage'];
                $netR = ($banked + ($exit - $entry) * $sign * $plan['value_per_unit'] * $remaining - $plan['commission_per_lot']) / $risk;
                $observation->update(['state' => $gap > config('trading.research.max_quote_gap_seconds') ? 'DATA_GAP' : 'COMPLETE', 'label_end_at' => $at, 'outcome' => ['net_r' => $netR, 'success' => $netR > 0, 'entry' => $entry, 'exit' => $exit, 'entry_at' => $enteredAt->toIso8601String(), 'reason' => $stopped ? 'STOP' : ($target ? 'TARGET' : 'HORIZON'), 'mfe_r' => $mfe, 'mae_r' => $mae, 'max_gap_seconds' => $gap, 'tick_fingerprint' => hash('sha256', json_encode($ids)), 'kind' => 'counterfactual_frozen_plan', 'includes_costs' => true]]);

                return;
            }
            $management = $plan['management'];
            if (isset($management['save_trigger_pips'], $management['save_fraction'], $management['pip_size']) && ! $saved && ($price - $entry) * $sign / $management['pip_size'] + 1e-8 >= $management['save_trigger_pips']) {
                $saveExit = $price - $sign * $plan['slippage'];
                if (($saveExit - $entry) * $sign * $plan['value_per_unit'] > $plan['commission_per_lot']) {
                    $fraction = $remaining * $management['save_fraction'];
                    $banked += ($saveExit - $entry) * $sign * $plan['value_per_unit'] * $fraction;
                    $remaining -= $fraction;
                    $saved = true;
                    if (($entry - $stop) * $sign > 0) {
                        $stop = $entry;
                    }
                }
            }
            if (isset($management['pip_size'], $management['lock_step_pips'])) {
                $lock = app(ProfitLock::class)->nextStop($plan['direction'], $entry, $price, $stop, $management['pip_size'], $management['lock_step_pips']);
                if ($lock) {
                    $stop = $lock['stop'];
                }
            }
        }
        if ($entry === null && now()->gte($deadline)) {
            $gap = max($gap, abs($previous->min($deadline)->diffInSeconds($deadline)));
            $observation->update(['state' => $gap > config('trading.research.max_quote_gap_seconds') ? 'DATA_GAP' : 'NO_ENTRY', 'outcome' => ['max_gap_seconds' => $gap]]);
        } elseif ($entry !== null && now()->gte($enteredAt->copy()->addSeconds($plan['horizon_seconds'] + config('trading.research.max_quote_gap_seconds')))) {
            $observation->update(['state' => 'DATA_GAP']);
        }
    }
}
