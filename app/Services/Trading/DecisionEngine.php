<?php

namespace App\Services\Trading;

use App\Models\AgentDecision;
use App\Models\MarketSnapshot;
use App\Models\RiskProfile;
use App\Models\Signal;
use Illuminate\Support\Facades\DB;

class DecisionEngine
{
    public function evaluate(Signal $signal): AgentDecision
    {
        return DB::transaction(function () use ($signal) {
            $rules = RiskProfile::current(true);
            $signal->refresh()->load('channel.profile');
            $agent = $signal->channel->agents()->where('role', 'champion')->first();
            $quote = MarketSnapshot::latestCurrent($signal->symbol);
            $reasons = [];
            $context = ['mode' => 'paper', 'policy_version' => 'rules-1.0'];
            $score = 0;
            if (! $agent || $agent->state !== 'PAPER') {
                $reasons[] = 'AGENT_NOT_IN_PAPER';
            }
            if (! $quote) {
                $reasons[] = 'NO_MARKET_DATA';
            }
            if ($signal->message->is_historical) {
                $reasons[] = 'HISTORICAL_SIGNAL_BACKTEST_ONLY';
            }
            if ($quote && $agent && $reasons === []) {
                $profile = $signal->channel->profile;
                $drift = $profile?->drift;
                $approval = app(RiskEngine::class)->evaluate($signal, $quote, $rules, $drift === 'DETECTED' ? 0.4 : 1);
                $history = ($profile?->metrics['sample_size'] ?? 0) >= 20 ? max(0, min(100, 50 + $profile->metrics['expectancy_r'] * 30)) : 50;
                $regime = $profile?->dna['regime'][$quote->regime] ?? null;
                $compatibility = ($regime['sample_size'] ?? 0) >= 10 ? max(0, min(100, 50 + $regime['expectancy_r'] * 30)) : 50;
                $latency = max(0, 100 - abs(now()->diffInSeconds($signal->signal_time)) / 3);
                $parts = ['parser' => $signal->confidence * 100, 'reward_risk' => min(100, $approval['reward_risk'] * 40), 'history' => $history, 'regime' => $compatibility, 'latency' => $latency];
                $score = round($parts['parser'] * 0.3 + $parts['reward_risk'] * 0.25 + $history * 0.2 + $compatibility * 0.15 + $latency * 0.1, 1);
                $reasons = $approval['reasons'];
                if ($score < 65) {
                    $reasons[] = 'SCORE_BELOW_PAPER_THRESHOLD';
                }
                if ($regime && $regime['sample_size'] >= 10 && $regime['expectancy_r'] < 0) {
                    $reasons[] = 'NEGATIVE_REGIME_EXPECTANCY';
                }
                $context += ['components' => $parts, 'risk' => $approval, 'drift' => $drift ?? 'INSUFFICIENT_DATA'];
                if ($reasons === []) {
                    $paper = app(PaperExecution::class)->open($signal, $agent, $quote, $approval);
                    $context['trade_id'] = $paper->id;
                    // Parallel exit experiment. It never contributes to account risk or promotion.
                    $challenger = $signal->channel->agents()->where('role', 'challenger')->first();
                    if ($challenger && $challenger->state === 'SHADOW' && count($signal->take_profits) > 1) {
                        $shadow = app(PaperExecution::class)->open($signal, $challenger, $quote, $approval, 'shadow', 'last_tp');
                        $targets = $signal->take_profits;
                        $shadow->update(['take_profit' => $targets[count($targets) - 1]]);
                    }
                }
            }

            return AgentDecision::create(['signal_id' => $signal->id, 'agent_id' => $agent?->id, 'snapshot_id' => $quote?->id, 'action' => $reasons === [] ? 'PAPER_OPENED' : (in_array('WAIT_RETRACEMENT', $reasons) ? 'WAIT' : 'SKIP'), 'score' => $score, 'reasons' => $reasons ?: ['RISK_APPROVED', 'PAPER_ONLY'], 'context' => $context]);
        }, 3);
    }
}
