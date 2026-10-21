<?php

namespace App\Services\Trading;

use App\Models\MarketSnapshot;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Services\Learning\ObservationRecorder;
use App\Services\Learning\SignalQualityGate;

class SignalAssessment
{
    // Assessment never opens a position. Every contender uses the same current quote.
    public function assess(Signal $signal, ?MarketSnapshot $quote, RiskProfile $rules): array
    {
        $signal->refresh()->load('channel.profile', 'message');
        $agent = $signal->channel->agents()->where('role', 'champion')->first();
        $reasons = [];
        $context = ['mode' => 'paper', 'policy_version' => 'symbol-selection-1'];
        $score = 0;
        $management = $approval = [];
        $executionSignal = $signal;
        if (! $agent || $agent->state !== 'PAPER') {
            $reasons[] = 'AGENT_NOT_IN_PAPER';
        }
        if (! $quote) {
            $reasons[] = 'NO_MARKET_DATA';
        }
        if ($signal->message->is_historical) {
            $reasons[] = 'HISTORICAL_SIGNAL_BACKTEST_ONLY';
        }
        if ($reasons === []) {
            $plan = app(ChannelPolicyResolver::class)->resolve($signal, $quote);
            $management = $plan['management'];
            $context['channel_management'] = $management;
            $reasons = $plan['reasons'];
            if ($reasons === []) {
                $plan = app(ChannelStrategyResolver::class)->resolve($signal, $plan, $quote);
                $strategyReasons = $plan['reasons'];
                $management = $plan['management'];
                $context['channel_management'] = $management;
                $context['channel_strategy'] = $plan['strategy'];
                $executionSignal = $plan['signal'];
                $context['resolved_levels'] = ['stop_loss' => $executionSignal->stop_loss, 'take_profits' => $executionSignal->take_profits];
                $profile = $signal->channel->profile;
                $drift = $profile?->drift;
                $approval = app(RiskEngine::class)->evaluate($executionSignal, $quote, $rules, $drift === 'DETECTED' ? 0.4 : 1, $management);
                if (isset($management['entry_config'])) {
                    $management['entry_budget'] = $approval['budget'];
                    $context['channel_management'] = $management;
                }
                $observation = app(ObservationRecorder::class)->capture($signal, $executionSignal, $quote, now(), $management);
                $quality = app(SignalQualityGate::class)->evaluate($observation);
                $context['ml'] = $quality;
                $history = ($profile?->metrics['sample_size'] ?? 0) >= 20 ? max(0, min(100, 50 + $profile->metrics['expectancy_r'] * 30)) : 50;
                $regime = $profile?->dna['regime'][$quote->regime] ?? null;
                $compatibility = ($regime['sample_size'] ?? 0) >= 10 ? max(0, min(100, 50 + $regime['expectancy_r'] * 30)) : 50;
                $latency = max(0, 100 - abs(now()->diffInSeconds($signal->signal_time)) / 3);
                $parts = ['parser' => $signal->confidence * 100, 'reward_risk' => min(100, $approval['reward_risk'] * 40), 'history' => $history, 'regime' => $compatibility, 'latency' => $latency];
                $score = round($parts['parser'] * .3 + $parts['reward_risk'] * .25 + $history * .2 + $compatibility * .15 + $latency * .1, 1);
                $reasons = array_values(array_unique(array_merge($approval['reasons'], $strategyReasons)));
                if ($quality['action'] === 'SKIP') {
                    $reasons[] = 'ML_LOW_EXPECTED_QUALITY';
                }
                if ($score < 65) {
                    $reasons[] = 'SCORE_BELOW_PAPER_THRESHOLD';
                }
                if ($regime && $regime['sample_size'] >= 10 && $regime['expectancy_r'] < 0) {
                    $reasons[] = 'NEGATIVE_REGIME_EXPECTANCY';
                }
                $context += ['components' => $parts, 'risk' => $approval, 'drift' => $drift ?? 'INSUFFICIENT_DATA'];
            }
        }

        return ['signal' => $signal, 'execution_signal' => $executionSignal, 'agent' => $agent, 'quote' => $quote, 'score' => $score, 'reasons' => $reasons, 'context' => $context, 'approval' => $approval, 'management' => $management];
    }
}
