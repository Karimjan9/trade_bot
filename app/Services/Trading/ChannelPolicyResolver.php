<?php

namespace App\Services\Trading;

use App\Models\ChannelPolicy;
use App\Models\MarketSnapshot;
use App\Models\Signal;
use App\Services\Analytics\ChannelUnits;
use Carbon\CarbonInterface;

class ChannelPolicyResolver
{
    public function resolve(Signal $signal, MarketSnapshot $quote, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();
        $missingStop = $signal->stop_loss === null;
        $missingTp = ! $signal->take_profits;
        $pip = app(ChannelUnits::class)->pipSize($signal->channel, $signal->symbol);
        $policy = ChannelPolicy::where('channel_id', $signal->channel_id)->where('symbol', $signal->symbol)->where('direction', $signal->direction)->where('pip_size', $pip)->where('created_at', '<=', $asOf)->where('evidence_end_at', '<', $signal->signal_time)->latest('id')->first();
        if ($policy && ($policy->status !== 'PAPER_ELIGIBLE' || ! $policy->expires_at || $policy->expires_at->lt($asOf))) {
            $policy = null;
        }
        if (($missingStop || $missingTp) && ! $policy) {
            return ['signal' => $signal, 'reasons' => ['CHANNEL_POLICY_NOT_LEARNED'], 'management' => []];
        }
        $resolved = clone $signal;
        $management = ['horizon_seconds' => app(ChannelUnits::class)->horizon($signal->channel, $signal->symbol), 'stop_source' => 'provider', 'target_source' => 'provider'];
        if ($policy) {
            $sign = $signal->direction === 'BUY' ? 1 : -1;
            $entry = ($signal->direction === 'BUY' ? $quote->ask : $quote->bid) + $sign * config('trading.symbols.'.$signal->symbol.'.slippage');
            if ($missingStop) {
                $resolved->stop_loss = $entry - $sign * $policy->sl_pips * $policy->pip_size;
            }
            if ($missingTp) {
                $resolved->take_profits = [$entry + $sign * $policy->target_pips * $policy->pip_size];
            }
            if ($resolved->status === 'needs_policy') {
                $resolved->status = 'ready';
            }
            $management = ['policy_id' => $policy->id, 'pip_size' => $policy->pip_size, 'lock_step_pips' => $policy->lock_step_pips, 'target_milestone_pips' => $policy->target_pips, 'trailing_target' => $missingTp, 'horizon_seconds' => $policy->horizon_seconds, 'stop_source' => $missingStop ? 'channel_policy' : 'provider', 'target_source' => $missingTp ? 'channel_policy' : 'provider'];
        }

        return ['signal' => $resolved, 'reasons' => [], 'management' => $management];
    }
}
