<?php

namespace App\Services\Analytics;

use App\Models\AuditLog;
use App\Models\ChannelStrategy;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\RiskProfile;
use App\Models\TelegramChannel;
use App\Services\Monitoring\SignalIssues;
use App\Services\Trading\EntryPlans;
use App\Services\Trading\ExitPlans;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ChannelStrategyLearner
{
    public function fit(TelegramChannel $channel, string $symbol, string $direction): ChannelStrategy
    {
        $settings = config('trading.strategies');
        $pip = app(ChannelUnits::class)->pipSize($channel, $symbol);
        $horizon = app(ChannelUnits::class)->horizon($channel, $symbol);
        $eligible = $channel->source === 'manual' || $channel->learning_eligible;
        $observations = collect();
        $nextStart = null;
        $overlaps = 0;
        $source = MlObservation::where('channel_id', $channel->id)->where('plan->symbol', $symbol)->where('plan->direction', $direction)->where('observed_at', '<', now())->orderByDesc('observed_at')->orderByDesc('id')->limit($settings['max_source_rows']);
        foreach ($source->cursor() as $observation) {
            if (config('trading.entries.enabled') && ! isset($observation->plan['entry_research'])) {
                continue;
            }
            if (($observation->plan['pip_size'] ?? null) !== $pip || ($observation->plan['research_horizon_seconds'] ?? $observation->plan['horizon_seconds'] ?? null) !== $horizon) {
                continue;
            }
            $windowEnd = isset($observation->plan['entry_deadline']) ? CarbonImmutable::parse($observation->plan['entry_deadline'])->addSeconds($horizon) : $observation->observed_at->copy()->addSeconds($horizon + config('trading.signal_max_age_seconds'));
            if ($nextStart && $windowEnd->gte($nextStart)) {
                $overlaps++;

                continue;
            }
            $observations->push($observation);
            $nextStart = $observation->observed_at;
            if ($observations->count() >= $settings['max_episodes']) {
                break;
            }
        }
        $observations = $observations->reverse()->values();
        $variants = $pip ? (config('trading.entries.enabled') ? app(EntryPlans::class)->variants($horizon, $pip) : app(ExitPlans::class)->variants($horizon, $pip)) : [];
        $episodes = [];
        $provenance = [];
        $omitted = 0;
        if ($eligible && $pip && $observations->count() >= $settings['min_train'] + $settings['min_validation'] + $settings['min_test']) {
            foreach ($observations as $observation) {
                $study = config('trading.entries.enabled') ? app(EntryResearch::class)->study($observation, $variants) : null;
                $episode = config('trading.entries.enabled') ? ($study ? ['returns' => collect($study->report['variants'])->map(fn ($r) => $r['net_r'])->all(), 'end' => $study->evidence_end_at, 'targets' => $observation->plan['research_targets'], 'tick_ids' => [$study->fingerprint]] : null) : $this->episode($observation, $horizon);
                if (! $episode) {
                    $omitted++;

                    continue;
                }
                $returns = $episode['returns'] ?? [];
                foreach ($variants as $key => $parameters) {
                    if (isset($returns[$key])) {
                        continue;
                    }
                    $r = app(StrategyReplay::class)->run($episode, $parameters);
                    if ($r === null) {
                        break;
                    }
                    $returns[$key] = $r;
                }
                if (count($returns) !== count($variants)) {
                    $omitted++;

                    continue;
                }
                $provenance[] = [$observation->id, $episode['targets'], $episode['tick_ids']];
                $episodes[] = ['observation_id' => $observation->id, 'start' => $observation->observed_at, 'end' => $episode['end'], 'returns' => $returns];
            }
        }
        $fingerprint = hash('sha256', json_encode(['channel-exits-1', hash_file('sha256', __FILE__), hash_file('sha256', base_path('app/Services/Analytics/StrategyReplay.php')), hash_file('sha256', base_path('app/Services/Trading/ExitPlans.php')), $channel->id, $symbol, $direction, $eligible, $pip, $horizon, $settings, $observations->map(fn ($o) => [$o->id, $o->plan])->all(), $provenance], JSON_THROW_ON_ERROR));
        if ($existing = ChannelStrategy::where('fingerprint', $fingerprint)->first()) {
            return $existing;
        }
        $rows = collect($episodes);
        $a = (int) floor($rows->count() * .6);
        $b = (int) floor($rows->count() * .8);
        $validationStart = $rows[$a]['start'] ?? null;
        $testStart = $rows[$b]['start'] ?? null;
        $train = $rows->take($a)->filter(fn ($r) => $validationStart && $r['end']->lt($validationStart))->values();
        $validation = $rows->slice($a, $b - $a)->filter(fn ($r) => $testStart && $r['end']->lt($testStart))->values();
        $test = $rows->slice($b)->values();
        $report = ['method' => 'channel-exits-1', 'pip_size' => $pip, 'horizon_seconds' => $horizon, 'observations' => $observations->count(), 'sampled_overlaps_omitted' => $overlaps, 'complete_episodes' => $rows->count(), 'omitted' => $omitted,
            'train_count' => $train->count(), 'validation_count' => $validation->count(), 'test_count' => $test->count(), 'purged' => $b - $train->count() - $validation->count(), 'variants' => [], 'test_start' => $testStart?->toIso8601String(),
            'note' => 'Frozen per-signal bid/ask replay with costs. Only tested exits, not proof that every possible strategy fails. Not realized account profit.'];
        if (config('trading.entries.enabled')) {
            $report['method'] = 'joint-entry-exit-1';
            $report['no_entry_counts_as_zero'] = true;
        }
        $status = ! $eligible ? 'LEARNING_DISABLED' : 'INSUFFICIENT_DATA';
        $parameters = null;
        if ($train->count() >= $settings['min_train'] && $validation->count() >= $settings['min_validation'] && $test->count() >= $settings['min_test']) {
            foreach ($variants as $key => $variant) {
                $report['variants'][$key] = ['train' => app(ChannelAnalytics::class)->metrics($train->map(fn ($r) => $r['returns'][$key])), 'validation' => app(ChannelAnalytics::class)->metrics($validation->map(fn ($r) => $r['returns'][$key])), 'test' => app(ChannelAnalytics::class)->metrics($test->map(fn ($r) => $r['returns'][$key]))];
            }
            $best = collect($report['variants'])->sortByDesc(fn ($v) => $v['train']['expectancy_r'])->keys()->first();
            $report['selected_in_train'] = $best;
            $bestStats = $report['variants'][$best];
            $base = $report['variants']['provider_first'];
            $passes = $bestStats['validation']['expectancy_r'] > 0 && $bestStats['test']['expectancy_r'] > 0 && $bestStats['test']['max_drawdown_r'] <= $base['test']['max_drawdown_r'] && $bestStats['test']['expectancy_r'] >= $base['test']['expectancy_r'] + $settings['min_improvement_r'];
            if ($passes) {
                $status = 'PAPER_ELIGIBLE';
                $parameters = $variants[$best];
            } elseif ($base['validation']['expectancy_r'] > 0 && $base['test']['expectancy_r'] > 0) {
                $status = 'PAPER_ELIGIBLE';
                $parameters = $variants['provider_first'];
                $report['selection_reason'] = 'PRESPECIFIED_BASELINE_RETAINED';
            } else {
                $status = 'SHADOW_ONLY';
            }
            $allNegative = collect($report['variants'])->every(fn ($v) => $v['validation']['expectancy_r'] <= $settings['exclusion_expectancy_r'] && $v['test']['expectancy_r'] <= $settings['exclusion_expectancy_r']);
            $days = $test->map(fn ($r) => $r['start']->format('Y-m-d'))->unique()->count();
            $report['test_distinct_days'] = $days;
            if ($allNegative && $test->count() >= $settings['min_exclusion_test'] && $days >= $settings['min_exclusion_days']) {
                $status = 'UNPROFITABLE';
                $parameters = null;
            }
            if ($parameters && config('trading.entries.enabled')) {
                $selectedKey = $parameters['key'];
                $parameters['risk_multiplier'] = min(1.0, config('trading.entries.drawdown_budget_r') / max(1.0, $report['variants'][$selectedKey]['train']['max_drawdown_r']));
                $report['risk_sizing_source'] = 'training_drawdown_only_capped_by_global_and_channel_limits';
            }
        }
        $evidenceEnd = $rows->max('end');

        return DB::transaction(function () use ($channel, $symbol, $direction, $fingerprint, $status, $parameters, $report, $evidenceEnd, $settings) {
            RiskProfile::current(true);
            $channel->refresh();
            if ($channel->source !== 'manual' && ! $channel->learning_eligible) {
                $status = 'LEARNING_DISABLED';
                $parameters = null;
            }
            if ($status === 'PAPER_ELIGIBLE') {
                $bad = ChannelStrategy::where('channel_id', $channel->id)->where('symbol', $symbol)->where('direction', $direction)->where('status', 'UNPROFITABLE')->latest('id')->first();
                if ($bad && CarbonImmutable::parse($report['test_start'])->lte($bad->created_at)) {
                    $status = 'RECOVERY_SHADOW';
                    $report['selection_reason'] = 'AWAITING_NEW_POST_EXCLUSION_TEST';
                }
                if (! $evidenceEnd || $evidenceEnd->copy()->addDays($settings['lifetime_days'])->lt(now())) {
                    $status = 'SHADOW_ONLY';
                    $report['selection_reason'] = 'STALE_EVIDENCE';
                }
            }
            $strategy = ChannelStrategy::firstOrCreate(['fingerprint' => $fingerprint], ['channel_id' => $channel->id, 'symbol' => $symbol, 'direction' => $direction, 'status' => $status, 'parameters' => $parameters, 'report' => $report, 'evidence_end_at' => $evidenceEnd, 'expires_at' => $evidenceEnd?->copy()->addDays($settings['lifetime_days'])]);
            $decisive = ChannelStrategy::where('channel_id', $channel->id)->whereIn('status', ['PAPER_ELIGIBLE', 'UNPROFITABLE'])->latest('id')->get()->unique(fn ($s) => $s->symbol.':'.$s->direction);
            $badCount = $decisive->where('status', 'UNPROFITABLE')->count();
            $operational = ChannelStrategy::where('channel_id', $channel->id)->whereIn('status', ['PAPER_ELIGIBLE', 'UNPROFITABLE', 'SHADOW_ONLY', 'RECOVERY_SHADOW'])->latest('id')->get()->unique(fn ($s) => $s->symbol.':'.$s->direction);
            $goodCount = $operational->where('status', 'PAPER_ELIGIBLE')->count();
            $scopes = MlObservation::where('channel_id', $channel->id)->get(['plan'])->map(fn ($o) => ($o->plan['symbol'] ?? '').':'.($o->plan['direction'] ?? ''))->unique();
            $allBad = $scopes->isNotEmpty() && $scopes->every(fn ($scope) => $decisive->contains(fn ($s) => $s->symbol.':'.$s->direction === $scope && $s->status === 'UNPROFITABLE'));
            $excluded = $allBad || ($channel->trade_state === 'EXCLUDED' && ! $goodCount);
            $state = $excluded ? 'EXCLUDED' : ($operational->isNotEmpty() && ! $goodCount ? 'MONITOR_ONLY' : ($operational->count() > $goodCount ? 'SELECTIVE' : ($goodCount ? 'PERSONALIZED' : 'PAPER_BASELINE')));
            $channel->update(['trade_state' => $state, 'trade_reason' => $excluded ? 'TESTED_EXIT_POLICIES_UNPROFITABLE' : ($badCount ? 'SOME_DIRECTIONS_UNPROFITABLE' : null)]);
            AuditLog::record('channel.strategy_evaluated', 'channel:'.$channel->id, null, ['strategy_id' => $strategy->id, 'status' => $strategy->status, 'trade_state' => $channel->trade_state]);
            app(SignalIssues::class)->strategy($strategy);

            return $strategy;
        });
    }

    private function episode(MlObservation $observation, int $horizon): ?array
    {
        $plan = $observation->plan;
        if ($observation->signal?->message?->raw_json['edit_date'] ?? null) {
            return null;
        }
        if (! isset($plan['entry_deadline'], $plan['slippage'], $plan['stop_loss'])) {
            return null;
        }
        $deadline = CarbonImmutable::parse($plan['entry_deadline']);
        $upper = $deadline->addSeconds($horizon + config('trading.research.max_quote_gap_seconds'))->min(now());
        $initial = MarketSnapshot::find($observation->snapshot_id);
        if (! $initial || $initial->recorded_at->gt($observation->observed_at)) {
            return null;
        }
        $ticks = MarketSnapshot::where('symbol', $plan['symbol'])->where('market_open', true)->whereBetween('recorded_at', [$observation->observed_at, $upper])
            ->where(fn ($q) => $q->where('recorded_at', '>', $observation->observed_at)->orWhere('id', '>', $initial->id))->orderBy('recorded_at')->orderBy('id')->limit(100001)->get()->prepend($initial);
        if ($ticks->count() > 100001) {
            return null;
        }
        $sign = $plan['direction'] === 'BUY' ? 1 : -1;
        $entry = $enteredAt = null;
        $previous = $observation->observed_at;
        $rows = $ids = [];
        foreach ($ticks as $i => $tick) {
            $at = $i === 0 ? $observation->observed_at : $tick->recorded_at;
            if (abs($previous->diffInSeconds($at)) > config('trading.research.max_quote_gap_seconds')) {
                return null;
            }
            $previous = $at;
            if ($entry === null) {
                if ($at->gt($deadline)) {
                    return null;
                }
                $candidate = ($sign === 1 ? $tick->ask : $tick->bid) + $sign * $plan['slippage'];
                if ($candidate < $plan['entry_min'] - $plan['slippage'] || $candidate > $plan['entry_max'] + $plan['slippage']) {
                    continue;
                }
                $entry = $candidate;
                $enteredAt = $at;
            }
            $rows[] = ['at' => $at, 'price' => $sign === 1 ? $tick->bid : $tick->ask];
            $ids[] = $tick->id;
            if ($at->gte($enteredAt->copy()->addSeconds($horizon))) {
                $targets = $plan['research_targets'] ?? ($observation->signal?->take_profits ?: [$plan['take_profit']]);
                $targets = array_values(array_filter($targets, fn ($v) => $v !== null));
                if (! $targets) {
                    return null;
                }

                return ['plan' => $plan, 'entry' => $entry, 'entry_at' => $enteredAt, 'stop' => isset($plan['relative_stop']) ? $entry - $sign * $plan['relative_stop'] : $plan['stop_loss'], 'targets' => $targets, 'ticks' => $rows, 'tick_ids' => $ids, 'end' => $at];
            }
        }

        return null;
    }
}
