<?php

namespace App\Services\Analytics;

use App\Services\Trading\ExitPlans;
use App\Services\Trading\ProfitLock;

class StrategyReplay
{
    public function run(array $episode, array $parameters): ?float
    {
        $plan = $episode['plan'];
        $entry = $episode['entry'];
        $stop = $episode['stop'];
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $target = app(ExitPlans::class)->levels($parameters, $entry, $plan['direction'], $episode['targets'])['target'];
        if (($entry - $stop) * $sign <= 0 || ($target !== null && ($target - $entry) * $sign <= 0)) {
            return null;
        }
        $risk = abs($entry - $stop) * $plan['value_per_unit'] + $plan['commission_per_lot'];
        $net = -$plan['commission_per_lot'];
        $remaining = 1.0;
        $saved = false;
        foreach ($episode['ticks'] as $tick) {
            $price = $tick['price'];
            $stopped = ($price - $stop) * $sign <= 0;
            $hit = $target !== null && ($price - $target) * $sign >= 0;
            $timed = $episode['entry_at']->diffInSeconds($tick['at']) >= $parameters['horizon_seconds'];
            if ($stopped || $hit || $timed) {
                $exit = $hit && ! $stopped ? $target : $price - $sign * $plan['slippage'];
                $net += ($exit - $entry) * $sign * $plan['value_per_unit'] * $remaining;

                return $net / $risk;
            }
            if (isset($parameters['save_trigger_pips']) && ! $saved && ($price - $entry) * $sign / $parameters['pip_size'] + 1e-8 >= $parameters['save_trigger_pips']) {
                $exit = $price - $sign * $plan['slippage'];
                if (($exit - $entry) * $sign * $plan['value_per_unit'] > $plan['commission_per_lot']) {
                    $net += ($exit - $entry) * $sign * $plan['value_per_unit'] * $parameters['save_fraction'];
                    $remaining -= $parameters['save_fraction'];
                    $saved = true;
                    if (($entry - $stop) * $sign > 0) {
                        $stop = $entry;
                    }
                }
            }
            if (isset($parameters['lock_step_pips'])) {
                $lock = app(ProfitLock::class)->nextStop($plan['direction'], $entry, $price, $stop, $parameters['pip_size'], $parameters['lock_step_pips']);
                if ($lock) {
                    $stop = $lock['stop'];
                }
            }
        }

        return null;
    }
}
