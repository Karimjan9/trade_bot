<?php

namespace App\Services\Trading;

use App\Jobs\ReconsiderSymbol;
use App\Models\AgentDecision;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use Illuminate\Support\Facades\DB;

class DecisionEngine
{
    public function evaluate(Signal $signal): AgentDecision
    {
        return $this->arbitrate($signal->symbol, $signal)[$signal->id];
    }

    public function evaluateSymbol(string $symbol): array
    {
        return $this->arbitrate($symbol);
    }

    private function arbitrate(string $symbol, ?Signal $requested = null): array
    {
        return DB::transaction(function () use ($symbol, $requested) {
            $rules = RiskProfile::current(true);
            $quote = MarketSnapshot::latestCurrent($symbol);
            $limit = config('trading.arbitration.max_candidates');
            $signals = Signal::where('symbol', $symbol)->whereIn('status', ['ready', 'needs_policy'])
                ->whereBetween('signal_time', [now()->subSeconds(config('trading.signal_max_age_seconds')), now()])
                ->whereHas('message', fn ($q) => $q->where('is_historical', false))->whereHas('channel', fn ($q) => $q->where('enabled', true))
                ->whereDoesntHave('trades', fn ($q) => $q->where('mode', 'paper'))->orderBy('id')->limit($limit + 1)->get()->keyBy('id');
            $overflow = $signals->count() > $limit;
            if ($requested) {
                $signals->put($requested->id, $requested);
            }
            $assessments = $signals->map(fn ($signal) => app(SignalAssessment::class)->assess($signal, $quote, $rules));
            $eligible = $assessments->filter(fn ($a) => $a['reasons'] === []);
            $held = PaperTrade::where('mode', 'paper')->where('status', 'open')->where('symbol', $symbol)->first();
            $selection = ['version' => 'symbol-selection-1', 'symbol' => $symbol, 'as_of' => now()->toIso8601String(), 'selected_signal_id' => null, 'candidates' => []];
            $waitReason = null;
            $winner = null;
            if ($overflow) {
                $waitReason = 'CANDIDATE_LIMIT_REQUIRES_REVIEW';
            } elseif ($held) {
                $waitReason = 'SYMBOL_POSITION_HELD';
                $selection['held_trade'] = ['id' => $held->id, 'direction' => $held->direction, 'signal_id' => $held->signal_id];
            } elseif ($eligible->isNotEmpty()) {
                $readyAt = $eligible->min(fn ($a) => $a['signal']->created_at)->copy()->addSeconds(config('trading.arbitration.collection_seconds'));
                if (now()->lt($readyAt)) {
                    $waitReason = 'COLLECTING_SYMBOL_CANDIDATES';
                    $selection['ready_at'] = $readyAt->toIso8601String();
                    if (config('queue.default') !== 'sync') {
                        ReconsiderSymbol::dispatch($symbol)->delay($readyAt)->afterCommit();
                    }
                } else {
                    $ranked = $eligible->map(fn ($a) => app(CandidateUtility::class)->estimate($a));
                    foreach ($ranked as $id => $estimate) {
                        $selection['candidates'][] = ['signal_id' => $id, 'channel_id' => $assessments[$id]['signal']->channel_id, 'direction' => $assessments[$id]['signal']->direction] + $estimate;
                    }
                    $comparable = $ranked->filter(fn ($r) => $r['comparable'])->sortByDesc('utility_r');
                    $directions = $eligible->map(fn ($a) => $a['signal']->direction)->unique();
                    if ($directions->count() > 1) {
                        if ($comparable->isEmpty()) {
                            $waitReason = 'OPPOSING_SIGNALS_NEED_MODEL_EVIDENCE';
                        } else {
                            $topId = $comparable->keys()->first();
                            $top = $comparable->first();
                            $opponent = $comparable->filter(fn ($r, $id) => $assessments[$id]['signal']->direction !== $assessments[$topId]['signal']->direction)->first();
                            if ($top['utility_r'] <= 0) {
                                $waitReason = 'NO_POSITIVE_DIRECTION_UTILITY';
                            } elseif ($opponent && config('trading.arbitration.min_edge_r') > $top['utility_r'] - $opponent['utility_r']) {
                                $waitReason = 'DIRECTION_EDGE_TOO_SMALL';
                            } else {
                                $winner = $topId;
                            }
                        }
                    } else {
                        // Same-direction duplicates never count as independent confirmation.
                        if ($comparable->isNotEmpty()) {
                            $winner = $comparable->filter(fn ($r) => $r['utility_r'] > 0)->keys()->first();
                            if (! $winner) {
                                $waitReason = 'NO_POSITIVE_DIRECTION_UTILITY';
                            }
                        } else {
                            $winner = $eligible->sortByDesc('score')->keys()->first();
                        }
                    }
                }
            }
            $selection['selected_signal_id'] = $winner;
            if ($winner) {
                $a = $assessments[$winner];
                $paper = app(PaperExecution::class)->open($a['execution_signal'], $a['agent'], $quote, $a['approval'], strategy: $a['management']['key'] ?? 'market', management: $a['management']);
                if ($a['signal']->status === 'needs_policy') {
                    $a['signal']->update(['status' => 'ready']);
                }
                $a['context']['trade_id'] = $paper->id;
                $challenger = $a['signal']->channel->agents()->where('role', 'challenger')->first();
                if ($challenger && $challenger->state === 'SHADOW' && count($a['execution_signal']->take_profits) > 1) {
                    $shadow = app(PaperExecution::class)->open($a['execution_signal'], $challenger, $quote, $a['approval'], 'shadow', 'last_tp');
                    $shadow->update(['take_profit' => collect($a['execution_signal']->take_profits)->last()]);
                }
                $assessments->put($winner, $a);
            }
            $decisions = [];
            foreach ($assessments as $id => $a) {
                $reasons = $a['reasons'];
                $action = 'SKIP';
                if ($id === $winner) {
                    $action = 'PAPER_OPENED';
                    $reasons = ['RISK_APPROVED', 'SYMBOL_CANDIDATE_SELECTED', 'PAPER_ONLY'];
                } elseif ($reasons === [] || ($held && in_array('EXISTING_SYMBOL_EXPOSURE', $reasons) && ! in_array('DUPLICATE_TRADE', $reasons))) {
                    $action = 'WAIT';
                    $reasons[] = $waitReason ?? 'OTHER_SYMBOL_CANDIDATE_SELECTED';
                } elseif (array_intersect($reasons, ['WAIT_RETRACEMENT', 'WAIT_ENTRY_LEVEL', 'NO_MARKET_DATA', 'STALE_OR_FUTURE_QUOTE', 'CHANNEL_POLICY_NOT_LEARNED'])) {
                    $action = 'WAIT';
                }
                $decisions[$id] = AgentDecision::create(['signal_id' => $id, 'agent_id' => $a['agent']?->id, 'snapshot_id' => $quote?->id, 'action' => $action, 'score' => $a['score'], 'reasons' => $reasons, 'context' => $a['context'] + ['selection' => $selection]]);
            }

            return $decisions;
        }, 3);
    }
}
