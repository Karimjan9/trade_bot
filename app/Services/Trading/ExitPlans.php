<?php

namespace App\Services\Trading;

class ExitPlans
{
    public function variants(int $horizon, float $pip): array
    {
        $base = ['horizon_seconds' => $horizon, 'pip_size' => $pip];

        return [
            'provider_first' => $base + ['key' => 'provider_first', 'target_mode' => 'first'],
            'provider_last' => $base + ['key' => 'provider_last', 'target_mode' => 'last'],
            'tp_50' => $base + ['key' => 'tp_50', 'target_mode' => 'pips', 'target_pips' => 50],
            'tp_100' => $base + ['key' => 'tp_100', 'target_mode' => 'pips', 'target_pips' => 100],
            'lock_50' => $base + ['key' => 'lock_50', 'target_mode' => 'none', 'lock_step_pips' => 50],
            'save_50' => $base + ['key' => 'save_50', 'target_mode' => 'none', 'lock_step_pips' => 50, 'save_trigger_pips' => 50, 'save_fraction' => .5],
            'time_5m' => array_replace($base, ['key' => 'time_5m', 'target_mode' => 'none', 'horizon_seconds' => min(300, $horizon)]),
        ];
    }

    public function levels(array $parameters, float $entry, string $direction, array $baseTargets): array
    {
        $sign = $direction === 'BUY' ? 1 : -1;
        $target = match ($parameters['target_mode']) {
            'first' => $baseTargets[0],
            'last' => end($baseTargets),
            'pips' => $entry + $sign * $parameters['target_pips'] * $parameters['pip_size'],
            default => null,
        };

        return ['target' => $target, 'milestone' => $target ?? $entry + $sign * 100 * $parameters['pip_size']];
    }
}
