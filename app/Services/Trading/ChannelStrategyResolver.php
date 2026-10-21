<?php

namespace App\Services\Trading;

use App\Models\ChannelStrategy;
use App\Models\MarketSnapshot;
use App\Models\Signal;
use App\Services\Analytics\ChannelUnits;

class ChannelStrategyResolver
{
    public function resolve(Signal $signal, array $base, MarketSnapshot $quote): array
    {
        $result = $base + ['strategy' => null];
        $strategy = ChannelStrategy::where('channel_id', $signal->channel_id)->where('symbol', $signal->symbol)->where('direction', $signal->direction)
            ->whereIn('status', ['PAPER_ELIGIBLE', 'UNPROFITABLE', 'SHADOW_ONLY', 'RECOVERY_SHADOW'])->where('created_at', '<=', now())->where('evidence_end_at', '<', $signal->signal_time)->latest('id')->first();
        $result['strategy'] = $strategy ? ['id' => $strategy->id, 'status' => $strategy->status, 'parameters' => $strategy->parameters] : null;
        if ($signal->channel->trade_state === 'EXCLUDED') {
            $result['reasons'][] = 'CHANNEL_UNPROFITABLE_MONITOR_ONLY';

            return $result;
        }
        if (! $strategy) {
            return $result;
        }
        if ($strategy->status === 'UNPROFITABLE') {
            $result['reasons'][] = 'CHANNEL_DIRECTION_UNPROFITABLE';

            return $result;
        }
        if (in_array($strategy->status, ['SHADOW_ONLY', 'RECOVERY_SHADOW'])) {
            $result['reasons'][] = 'CHANNEL_STRATEGY_MONITOR_ONLY';

            return $result;
        }
        if (! $strategy->expires_at || $strategy->expires_at->lt(now())) {
            $result['reasons'][] = 'CHANNEL_STRATEGY_EXPIRED';

            return $result;
        }
        $parameters = $strategy->parameters;
        if (($parameters['pip_size'] ?? null) !== app(ChannelUnits::class)->pipSize($signal->channel, $signal->symbol)) {
            $result['reasons'][] = 'CHANNEL_STRATEGY_UNIT_CHANGED';

            return $result;
        }
        $resolved = clone $base['signal'];
        $sign = $signal->direction === 'BUY' ? 1 : -1;
        $entry = ($sign === 1 ? $quote->ask : $quote->bid) + $sign * config('trading.symbols.'.$signal->symbol.'.slippage');
        $levels = app(ExitPlans::class)->levels($parameters, $entry, $signal->direction, $resolved->take_profits);
        $management = $base['management'];
        $management['research_targets'] = $resolved->take_profits;
        $management['research_stop_loss'] = $resolved->stop_loss;
        $management['research_horizon_seconds'] = $management['horizon_seconds'];
        unset($management['lock_step_pips'], $management['target_milestone_pips']);
        $management = array_replace($management, $parameters, ['strategy_id' => $strategy->id, 'policy_family' => 'channel_strategy:'.$parameters['key'], 'horizon_seconds' => $parameters['horizon_seconds'], 'trailing_target' => $levels['target'] === null, 'target_source' => 'channel_strategy']);
        $resolved->take_profits = [$levels['milestone']];
        if (isset($parameters['entry_key'])) {
            $frozen = $signal->entry_plan;
            if (! $frozen) {
                $frozen = app(EntryPlans::class)->freeze(['direction' => $signal->direction, 'entry_min' => $signal->entry_min, 'entry_max' => $signal->entry_max, 'stop_loss' => $resolved->stop_loss, 'entry_deadline' => $signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds'))->toIso8601String()], $parameters, $sign === 1 ? $quote->ask : $quote->bid);
                $frozen['strategy_id'] = $strategy->id;
                $signal->update(['entry_plan' => $frozen]);
            }
            if (($frozen['strategy_id'] ?? null) !== $strategy->id) {
                $result['reasons'][] = 'ENTRY_POLICY_CHANGED_DURING_WAIT';
            }
            $resolved->stop_loss = $frozen['stop_loss'];
            $management['entry_config'] = $frozen;
        }
        $result['signal'] = $resolved;
        $result['management'] = $management;

        return $result;
    }
}
