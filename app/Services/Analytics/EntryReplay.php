<?php

namespace App\Services\Analytics;

use App\Services\Trading\EntryPlans;
use App\Services\Trading\ExitPlans;
use App\Services\Trading\ProfitLock;
use Carbon\CarbonImmutable;

class EntryReplay
{
    /** A frozen, single-direction position with fixed-size legs and a common stop. */
    public function run(array $plan, array $ticks, array $parameters, float $budget = 100): ?array
    {
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $first = $ticks[0];
        $reference = $sign === 1 ? $first['ask'] : $first['bid'];
        $entryPlan = $plan['frozen_entry'] ?? app(EntryPlans::class)->freeze($plan, $parameters, $reference);
        $stop = $entryPlan['stop_loss'];
        $spec = $plan['entry_spec'];
        // For a touch entry, reserve from the first fillable quote, not an outside quote.
        $sizing = collect($ticks)->first(fn ($t) => app(EntryPlans::class)->eligible($entryPlan, $entryPlan['limits'][0], $plan['direction'], $sign === 1 ? $t['ask'] : $t['bid']) && $t['at']->lte(CarbonImmutable::parse($entryPlan['deadline'])));
        if (! $sizing) {
            return $this->empty('NO_ENTRY', $entryPlan);
        }
        $allocation = app(EntryPlans::class)->allocate($entryPlan, $plan['direction'], $sign === 1 ? $sizing['ask'] : $sizing['bid'], $budget, $spec, $plan['commission_per_lot']);
        if ($allocation['reason']) {
            return $this->empty($allocation['reason'], $entryPlan);
        }
        $legs = $allocation['legs'];
        $risk = $allocation['risk_amount'];
        $volume = $entry = $net = $mfe = $mae = 0.0;
        $remaining = 1.0;
        $entered = $target = null;
        $saved = $protected = false;
        $deadline = CarbonImmutable::parse($entryPlan['deadline']);
        foreach ($ticks as $tick) {
            $price = $sign === 1 ? $tick['bid'] : $tick['ask'];
            if ($volume > 0) {
                $stopped = ($price - $stop) * $sign <= 0;
                $hit = $target !== null && ($price - $target) * $sign >= 0;
                $timed = $tick['at']->gte($entered->copy()->addSeconds($parameters['horizon_seconds']));
                if ($stopped || $hit || $timed) {
                    $exit = $hit && ! $stopped ? $target : $price - $sign * $spec['slippage'];
                    $net += ($exit - $entry) * $sign * $volume * $plan['value_per_unit'] * $remaining;
                    $legs = array_map(fn ($leg) => $leg['status'] === 'PENDING' ? array_replace($leg, ['status' => 'CANCELLED']) : $leg, $legs);

                    return ['net_r' => $net / $risk, 'net_pnl' => $net, 'risk_amount' => $risk, 'entry' => $entry, 'entry_at' => $entered->toIso8601String(), 'exit' => $exit, 'end_at' => $tick['at']->toIso8601String(), 'reason' => $stopped ? 'STOP' : ($hit ? 'TARGET' : 'HORIZON'), 'legs' => $legs, 'mfe_r' => $mfe, 'mae_r' => $mae, 'stop_loss' => $entryPlan['stop_loss']];
                }
            }
            if (! $protected && $tick['at']->lte($deadline)) {
                $fill = ($sign === 1 ? $tick['ask'] : $tick['bid']);
                foreach ($legs as &$leg) {
                    if ($leg['status'] !== 'PENDING' || ! app(EntryPlans::class)->eligible($entryPlan, $leg['limit'], $plan['direction'], $fill)) {
                        continue;
                    }
                    $actual = $fill + $sign * $spec['slippage'];
                    if (($actual - $stop) * $sign <= 0) {
                        $leg['status'] = 'CANCELLED';

                        continue;
                    }
                    $entry = ($entry * $volume + $actual * $leg['volume']) / ($volume + $leg['volume']);
                    $volume += $leg['volume'];
                    $net -= $leg['volume'] * $plan['commission_per_lot'];
                    $leg = array_merge($leg, ['status' => 'FILLED', 'price' => $actual, 'at' => $tick['at']->toIso8601String()]);
                    if ($entered === null) {
                        $entered = $tick['at'];
                        $targets = $plan['research_targets'] ?? [$plan['take_profit']];
                        $target = app(ExitPlans::class)->levels($parameters, $actual, $plan['direction'], $targets)['target'];
                        if ($target !== null && ($target - $actual) * $sign <= 0) {
                            return $this->empty('INVALID_TARGET', $entryPlan);
                        }
                    }
                }
                unset($leg);
            }
            if ($volume <= 0) {
                if ($tick['at']->gt($deadline)) {
                    return $this->empty('NO_ENTRY', $entryPlan);
                }

                continue;
            }
            if ($tick['at']->gt($deadline) || $protected) {
                $legs = array_map(fn ($leg) => $leg['status'] === 'PENDING' ? array_replace($leg, ['status' => 'CANCELLED']) : $leg, $legs);
            }
            $floatingR = ($net + ($price - $entry) * $sign * $volume * $plan['value_per_unit'] * $remaining) / $risk;
            $mfe = max($mfe, $floatingR);
            $mae = min($mae, $floatingR);
            if (isset($parameters['save_trigger_pips']) && ! $saved && ($price - $entry) * $sign / $parameters['pip_size'] + 1e-8 >= $parameters['save_trigger_pips']) {
                $exit = $price - $sign * $spec['slippage'];
                if (($exit - $entry) * $sign * $volume * $plan['value_per_unit'] > $volume * $plan['commission_per_lot']) {
                    $net += ($exit - $entry) * $sign * $volume * $plan['value_per_unit'] * $parameters['save_fraction'];
                    $remaining -= $parameters['save_fraction'];
                    $saved = $protected = true;
                    $stop = $entry;
                }
            }
            if (isset($parameters['lock_step_pips'])) {
                $lock = app(ProfitLock::class)->nextStop($plan['direction'], $entry, $price, $stop, $parameters['pip_size'], $parameters['lock_step_pips']);
                if ($lock) {
                    $stop = $lock['stop'];
                    $protected = true;
                }
            }
        }

        return null;
    }

    private function empty(string $reason, array $entryPlan): array
    {
        return ['net_r' => 0.0, 'net_pnl' => 0.0, 'reason' => $reason, 'legs' => [], 'entry' => null, 'stop_loss' => $entryPlan['stop_loss'], 'mfe_r' => 0.0, 'mae_r' => 0.0];
    }
}
