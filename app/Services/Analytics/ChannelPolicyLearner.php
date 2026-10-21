<?php

namespace App\Services\Analytics;

use App\Models\ChannelPolicy;
use App\Models\TelegramChannel;
use App\Services\Signals\SignalParser;
use App\Services\Trading\ProfitLock;
use Illuminate\Support\Collection;

class ChannelPolicyLearner
{
    public function fit(TelegramChannel $channel, string $symbol, string $direction): ?ChannelPolicy
    {
        $pip = app(ChannelUnits::class)->pipSize($channel, $symbol);
        if (! $pip) {
            return null;
        }
        $horizon = app(ChannelUnits::class)->horizon($channel, $symbol);
        $spec = config('trading.symbols.'.$symbol);
        $episodes = [];
        $observations = [];
        $omitted = ['missing_coverage' => 0, 'no_entry' => 0, 'edited_before_observation' => 0];
        // Original observations, including messages later edited or deleted.
        $messages = $channel->messages()->where('posted_at', '<=', now()->subSeconds($horizon))->latest('posted_at')->limit(config('trading.research.max_episodes'))->get()->sortBy('posted_at')->values();
        foreach ($messages as $message) {
            $parsed = app(SignalParser::class)->parse($message->raw_text);
            $data = $parsed['signal'] ?? $parsed['candidate'] ?? null;
            if (! $data || $data['symbol'] !== $symbol || $data['direction'] !== $direction) {
                continue;
            }
            if ($message->raw_json['edit_date'] ?? null) {
                $observations[] = [$message->id, hash('sha256', $message->raw_text), 'edited_before_observation'];
                $omitted['edited_before_observation']++;

                continue;
            }
            $end = $message->posted_at->copy()->addSeconds($horizon);
            $window = app(PriceWindow::class)->read($symbol, $message->posted_at, $end);
            $observations[] = [$message->id, hash('sha256', $message->raw_text), $window['ticks']->pluck('id')->all()];
            if (! $window['covered']) {
                $omitted['missing_coverage']++;

                continue;
            }
            $entryTick = $window['ticks']->first(function ($tick) use ($data, $direction, $message) {
                $price = $direction === 'BUY' ? $tick->ask : $tick->bid;

                return $tick->recorded_at->lte($message->posted_at->copy()->addSeconds(config('trading.signal_max_age_seconds'))) && $price >= $data['entry_min'] && $price <= $data['entry_max'];
            });
            if (! $entryTick) {
                $omitted['no_entry']++;

                continue;
            }
            $entry = ($direction === 'BUY' ? $entryTick->ask : $entryTick->bid) + ($direction === 'BUY' ? 1 : -1) * $spec['slippage'];
            $ticks = $window['ticks']->filter(fn ($t) => $t->recorded_at->gte($entryTick->recorded_at))->values();
            $moves = $ticks->map(fn ($t) => (($direction === 'BUY' ? $t->bid : $t->ask) - $entry) * ($direction === 'BUY' ? 1 : -1) / $pip);
            $episodes[] = ['message_id' => $message->id, 'original_hash' => hash('sha256', $message->raw_text), 'start' => $message->posted_at, 'end' => $end, 'entry' => $entry, 'ticks' => $ticks, 'mae' => max(0, -$moves->min()), 'mfe' => max(0, $moves->max())];
        }
        $all = collect($episodes);
        $split = (int) floor($all->count() * 2 / 3);
        $validation = $all->slice($split)->values();
        $validationStart = $validation->first()['start'] ?? null;
        $train = $all->take($split)->filter(fn ($e) => $validationStart && $e['end']->lt($validationStart))->values();
        $fingerprint = hash('sha256', json_encode([$channel->id, $symbol, $direction, $pip, $horizon, config('trading.research'), $spec, config('trading.commission_per_lot_round_trip'), $observations]));
        if ($existing = ChannelPolicy::where('fingerprint', $fingerprint)->first()) {
            return $existing;
        }
        $evidence = ['train_count' => $train->count(), 'validation_count' => $validation->count(), 'omitted' => $omitted, 'purged_overlaps' => $split - $train->count(), 'method' => 'Independent tick replay; chronological holdout; overlapping training windows purged; fixed costs; provider levels are not rewritten.'];
        $values = ['channel_id' => $channel->id, 'symbol' => $symbol, 'direction' => $direction, 'fingerprint' => $fingerprint, 'pip_size' => $pip, 'horizon_seconds' => $horizon, 'status' => 'INSUFFICIENT_DATA', 'evidence' => $evidence, 'evidence_end_at' => $all->last()['end'] ?? null, 'expires_at' => isset($all->last()['end']) ? $all->last()['end']->copy()->addDays(config('trading.research.policy_lifetime_days')) : null];
        if ($train->count() < config('trading.research.min_train') || $validation->count() < config('trading.research.min_validation')) {
            return ChannelPolicy::firstOrCreate(['fingerprint' => $fingerprint], $values);
        }
        $stops = $this->candidates($train->pluck('mae'), 1.1, 1);
        $targets = $this->candidates($train->pluck('mfe')->filter(fn ($v) => $v > 0)->values(), 1, 0);
        if (! $targets) {
            return ChannelPolicy::firstOrCreate(['fingerprint' => $fingerprint], $values);
        }
        $best = null;
        foreach ($stops as $stop) {
            foreach ($targets as $target) {
                $metrics = $this->score($train, $direction, $pip, $stop, $target / 2, $spec);
                if ($best === null || $metrics['expectancy_r'] > $best['train']['expectancy_r']) {
                    $best = ['stop' => $stop, 'target' => $target, 'train' => $metrics];
                }
            }
        }
        $out = $this->score($validation, $direction, $pip, $best['stop'], $best['target'] / 2, $spec);
        $values['sl_pips'] = $best['stop'];
        $values['target_pips'] = $best['target'];
        $values['lock_step_pips'] = $best['target'] / 2;
        $values['status'] = $best['train']['expectancy_r'] > 0 && $out['expectancy_r'] > 0 ? 'PAPER_ELIGIBLE' : 'REJECTED';
        $values['evidence'] += ['train' => $best['train'], 'validation' => $out, 'candidate_count' => count($stops) * count($targets), 'note' => 'Positive holdout is a paper research gate, not a live promotion or profitability guarantee.'];

        return ChannelPolicy::firstOrCreate(['fingerprint' => $fingerprint], $values);
    }

    private function candidates(Collection $values, float $multiplier, float $padding): array
    {
        if ($values->isEmpty()) {
            return [];
        }
        $sorted = $values->sort()->values();
        $candidates = [];
        foreach ([0.25, 0.5, 0.75] as $q) {
            $candidates[] = max(1, round($sorted[(int) floor(($sorted->count() - 1) * $q)] * $multiplier + $padding, 2));
        }

        return array_values(array_unique($candidates));
    }

    private function score(Collection $episodes, string $direction, float $pip, float $slPips, float $step, array $spec): array
    {
        $returns = [];
        $sign = $direction === 'BUY' ? 1 : -1;
        $commission = config('trading.commission_per_lot_round_trip');
        foreach ($episodes as $episode) {
            $entry = $episode['entry'];
            $stop = $entry - $sign * $slPips * $pip;
            $exit = $entry;
            foreach ($episode['ticks'] as $tick) {
                $price = $direction === 'BUY' ? $tick->bid : $tick->ask;
                $exit = $price - $sign * $spec['slippage'];
                if (($price - $stop) * $sign <= 0) {
                    break;
                }
                $lock = app(ProfitLock::class)->nextStop($direction, $entry, $price, $stop, $pip, $step);
                if ($lock) {
                    $stop = $lock['stop'];
                }
            }
            $net = ($exit - $entry) * $sign * $spec['value_per_unit'] - $commission;
            $returns[] = $net / ($slPips * $pip * $spec['value_per_unit'] + $commission);
        }

        return app(ChannelAnalytics::class)->metrics(collect($returns));
    }
}
