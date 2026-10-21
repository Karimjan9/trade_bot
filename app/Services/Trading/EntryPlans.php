<?php

namespace App\Services\Trading;

class EntryPlans
{
    public function variants(int $horizon, float $pip): array
    {
        $exits = app(ExitPlans::class)->variants($horizon, $pip);
        $result = array_map(fn ($p) => $p + ['entry_key' => 'zone_touch'], $exits);
        $entries = [
            'zone_mid' => ['entry_key' => 'zone_mid'], 'zone_deep' => ['entry_key' => 'zone_deep'],
            'wait_50' => ['entry_key' => 'wait_50'], 'ladder_3' => ['entry_key' => 'ladder_3'],
            'touch_sl60' => ['entry_key' => 'zone_touch', 'stop_pips' => 60],
            'wait50_sl60' => ['entry_key' => 'wait_50', 'stop_pips' => 60],
            'ladder_sl60' => ['entry_key' => 'ladder_3', 'stop_pips' => 60],
            'touch_sl90' => ['entry_key' => 'zone_touch', 'stop_pips' => 90],
            'ladder_sl90' => ['entry_key' => 'ladder_3', 'stop_pips' => 90],
        ];
        foreach ($entries as $name => $entry) {
            foreach (['provider_first', 'provider_last', 'lock_50'] as $exit) {
                $key = $name.'__'.$exit;
                $result[$key] = array_replace($exits[$exit], $entry, ['key' => $key]);
            }
        }

        return $result;
    }

    public function freeze(array $plan, array $parameters, float $reference): array
    {
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $anchor = min($plan['entry_max'], max($plan['entry_min'], $reference));
        $key = $parameters['entry_key'] ?? 'zone_touch';
        $mid = ($plan['entry_min'] + $plan['entry_max']) / 2;
        $deep = $sign === 1 ? $plan['entry_min'] : $plan['entry_max'];
        $touch = $sign === 1 ? $plan['entry_max'] : $plan['entry_min'];
        $limits = match ($key) {
            'zone_mid' => [$mid], 'zone_deep' => [$deep], 'wait_50' => [$anchor - $sign * 50 * $parameters['pip_size']],
            'ladder_3' => [$touch, $mid, $deep], default => [null],
        };
        $stop = isset($parameters['stop_pips']) ? $anchor - $sign * $parameters['stop_pips'] * $parameters['pip_size'] : $plan['stop_loss'];

        return ['entry_key' => $key, 'anchor' => $anchor, 'stop_loss' => $stop, 'limits' => $limits, 'entry_min' => $plan['entry_min'], 'entry_max' => $plan['entry_max'], 'deadline' => $plan['entry_deadline']];
    }

    public function eligible(array $plan, ?float $limit, string $direction, float $price): bool
    {
        return $price >= $plan['entry_min'] - 1e-8 && $price <= $plan['entry_max'] + 1e-8
            && ($limit === null || ($direction === 'BUY' ? $price <= $limit + 1e-8 : $price >= $limit - 1e-8));
    }

    /** All legs share one finite budget. Fixed weights never increase after a loss. */
    public function allocate(array $entryPlan, string $direction, float $price, float $budget, array $spec, float $commission): array
    {
        $sign = $direction === 'BUY' ? 1 : -1;
        $legs = [];
        foreach ($entryPlan['limits'] as $limit) {
            $planned = $limit ?? $price;
            if ($planned < $entryPlan['entry_min'] - 1e-8 || $planned > $entryPlan['entry_max'] + 1e-8) {
                return ['legs' => [], 'risk_amount' => 0, 'reason' => 'ENTRY_LEVEL_OUTSIDE_ZONE'];
            }
            $worst = $planned + $sign * $spec['slippage'];
            $distance = ($worst - $entryPlan['stop_loss']) * $sign;
            if ($distance <= 0) {
                return ['legs' => [], 'risk_amount' => 0, 'reason' => 'ENTRY_STOP_INSIDE_LADDER'];
            }
            $perLot = ($distance + $spec['slippage']) * $spec['value_per_unit'] + $commission;
            $volume = floor(($budget / count($entryPlan['limits']) / $perLot + 1e-10) / $spec['volume_step']) * $spec['volume_step'];
            $maximum = floor($spec['volume_max'] / count($entryPlan['limits']) / $spec['volume_step']) * $spec['volume_step'];
            $volume = round(min($volume, $maximum), 8);
            if ($volume < $spec['volume_min']) {
                return ['legs' => [], 'risk_amount' => 0, 'reason' => 'ENTRY_LEG_BELOW_MINIMUM_VOLUME'];
            }
            $legs[] = ['limit' => $limit, 'volume' => $volume, 'reserved_risk' => $volume * $perLot, 'status' => 'PENDING'];
        }

        return ['legs' => $legs, 'risk_amount' => array_sum(array_column($legs, 'reserved_risk')), 'reason' => null];
    }

    public function label(string $key): string
    {
        $labels = ['provider_first' => 'Birinchi TP', 'provider_last' => 'Oxirgi TP', 'tp_50' => '50 pipsda yopish', 'tp_100' => '100 pipsda yopish', 'lock_50' => '50 pips qadamli himoya', 'save_50' => '50 pipsda yarmini yopish', 'time_5m' => '5 minutda chiqish', 'zone_mid' => 'Zona o‘rtasini kutish', 'zone_deep' => 'Zona ichkari chegarasini kutish', 'wait_50' => '50 pips qaytishni kutish', 'ladder_3' => 'Zona bo‘ylab 3 qism', 'touch_sl60' => 'Zonaga kelganda · SL 60', 'wait50_sl60' => '50 pips qaytish · SL 60', 'ladder_sl60' => '3 qism · SL 60', 'touch_sl90' => 'Zonaga kelganda · SL 90', 'ladder_sl90' => '3 qism · SL 90'];

        return implode(' / ', array_map(fn ($part) => $labels[$part] ?? $part, explode('__', $key)));
    }
}
