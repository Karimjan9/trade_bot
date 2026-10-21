<?php

namespace App\Services\Learning;

use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\Signal;
use App\Services\Analytics\ChannelUnits;
use Carbon\CarbonInterface;

class ObservationRecorder
{
    public function capture(Signal $signal, Signal $resolved, MarketSnapshot $quote, CarbonInterface $at, array $management = [], string $origin = 'realtime'): ?MlObservation
    {
        if ($signal->signal_time->gt($at) || $signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds'))->lt($at) || $quote->recorded_at->gt($at) || $quote->recorded_at->lt($at->copy()->subSeconds(config('trading.quote_max_age_seconds'))) || $resolved->stop_loss === null || ! $resolved->take_profits || ! $quote->market_open) {
            return null;
        }
        if ($existing = MlObservation::where('signal_id', $signal->id)->first()) {
            return $existing;
        }
        $features = app(MarketFeatures::class)->build($resolved, $quote, $at, $management);
        $plan = ['symbol' => $signal->symbol, 'direction' => $signal->direction, 'entry_min' => $signal->entry_min, 'entry_max' => $signal->entry_max, 'entry_deadline' => $signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds'))->toIso8601String(), 'stop_loss' => $resolved->stop_loss, 'take_profit' => ($management['trailing_target'] ?? false) ? null : $resolved->take_profits[0], 'management' => $management, 'horizon_seconds' => $management['horizon_seconds'] ?? app(ChannelUnits::class)->horizon($signal->channel, $signal->symbol), 'slippage' => config('trading.symbols.'.$signal->symbol.'.slippage'), 'value_per_unit' => config('trading.symbols.'.$signal->symbol.'.value_per_unit'), 'commission_per_lot' => config('trading.commission_per_lot_round_trip'), 'family' => ($management['trailing_target'] ?? false) ? 'channel_trailing' : 'fixed_tp'];
        if (($management['stop_source'] ?? null) === 'channel_policy') {
            $plan['relative_stop'] = abs(($resolved->direction === 'BUY' ? $quote->ask : $quote->bid) + ($resolved->direction === 'BUY' ? 1 : -1) * $plan['slippage'] - $resolved->stop_loss);
        }
        $plan['pip_size'] = app(ChannelUnits::class)->pipSize($signal->channel, $signal->symbol);
        $plan['research_targets'] = $management['research_targets'] ?? $resolved->take_profits;
        $plan['research_stop_loss'] = $management['research_stop_loss'] ?? $resolved->stop_loss;
        $plan['research_horizon_seconds'] = $management['research_horizon_seconds'] ?? $plan['horizon_seconds'];
        if (config('trading.entries.enabled')) {
            $plan['entry_spec'] = config('trading.symbols.'.$signal->symbol);
            $plan['entry_research'] = ['budget' => config('trading.entries.research_budget')];
        }
        if (isset($management['entry_config'])) {
            $plan['frozen_entry'] = $management['entry_config'];
            $plan['entry_budget'] = $management['entry_budget'] ?? config('trading.entries.research_budget');
        }
        $plan['family'] = $management['policy_family'] ?? $plan['family'];
        $plan['trajectory_horizon_seconds'] = config('trading.trajectory.horizon_seconds');
        $plan['trajectory_checkpoints_seconds'] = config('trading.trajectory.checkpoints_seconds');
        $plan['trajectory_milestones_pips'] = config('trading.trajectory.milestones_pips');

        return MlObservation::firstOrCreate(['signal_id' => $signal->id], ['channel_id' => $signal->channel_id, 'snapshot_id' => $quote->id, 'feature_version' => MarketFeatures::VERSION, 'features' => $features['features'], 'context' => $features['context'], 'plan' => $plan, 'origin' => $origin, 'observed_at' => $at]);
    }
}
