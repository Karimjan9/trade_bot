<?php

namespace App\Services\Trading;

use App\Models\MlObservation;
use Carbon\CarbonImmutable;

class CandidateUtility
{
    public function estimate(array $assessment): array
    {
        $ml = $assessment['context']['ml'] ?? [];
        $signal = $assessment['signal'];
        if (($ml['action'] ?? null) !== 'ALLOW') {
            return ['comparable' => false, 'reason' => 'NO_VALIDATED_DIRECTION_ESTIMATE'];
        }
        $horizon = $assessment['management']['horizon_seconds'];
        $family = $assessment['management']['policy_family'] ?? (($assessment['management']['trailing_target'] ?? false) ? 'channel_trailing' : 'fixed_tp');
        $history = MlObservation::where('channel_id', $signal->channel_id)->where('state', 'COMPLETE')->where('label_end_at', '<', now())->where('observed_at', '<', $signal->signal_time)->orderByDesc('observed_at')->limit(200)->get()
            ->filter(fn ($o) => ($o->plan['symbol'] ?? null) === $signal->symbol && ($o->plan['direction'] ?? null) === $signal->direction && ($o->plan['family'] ?? null) === $family && ($o->plan['horizon_seconds'] ?? null) === $horizon);
        $enough = $history->count() >= config('trading.arbitration.min_history');
        $adverse = 1.0;
        $holding = $horizon;
        if ($enough) {
            $values = $history->map(fn ($o) => abs(min(0, $o->outcome['mae_r'] ?? -1)))->sort()->values();
            $adverse = $values[(int) floor(($values->count() - 1) * .8)];
            $holding = $history->map(fn ($o) => isset($o->outcome['entry_at']) ? abs(CarbonImmutable::parse($o->outcome['entry_at'])->diffInSeconds($o->label_end_at)) : $horizon)->median();
        }
        $utility = $ml['expected_r'] - config('trading.arbitration.adverse_penalty') * $adverse - config('trading.arbitration.holding_penalty_r_per_hour') * $holding / 3600;

        return ['comparable' => true, 'utility_r' => $utility, 'expected_net_r' => $ml['expected_r'], 'probability' => $ml['probability'], 'adverse_r' => $adverse, 'holding_seconds' => $holding, 'plan_horizon_seconds' => $horizon, 'history_count' => $history->count(), 'risk_time_source' => $enough ? 'prior_frozen_plan_outcomes' : 'conservative_plan_fallback', 'model_id' => $ml['model_id']];
    }
}
