<?php

namespace App\Services\Learning;

use App\Models\MarketSnapshot;
use App\Models\Signal;
use Carbon\CarbonInterface;

class MarketFeatures
{
    public const VERSION = 'causal-market-1';

    public function build(Signal $signal, MarketSnapshot $quote, CarbonInterface $at, array $management = []): array
    {
        // A minute is usable only after its close; no unfinished/future candle enters a feature.
        $closedBefore = $at->copy()->startOfMinute();
        $ticks = MarketSnapshot::where('symbol', $signal->symbol)->where('market_open', true)->where('recorded_at', '>=', $at->copy()->subMinutes(config('trading.ml.history_minutes')))->where('recorded_at', '<', $closedBefore)->orderBy('recorded_at')->orderBy('id')->limit(30000)->get();
        $bars = [];
        foreach ($ticks as $tick) {
            $key = $tick->recorded_at->format('Y-m-d H:i');
            $price = ($tick->bid + $tick->ask) / 2;
            if (! isset($bars[$key])) {
                $bars[$key] = ['high' => $price, 'low' => $price, 'close' => $price];
            }
            $bars[$key]['high'] = max($bars[$key]['high'], $price);
            $bars[$key]['low'] = min($bars[$key]['low'], $price);
            $bars[$key]['close'] = $price;
        }
        $recentComplete = true;
        for ($minute = 1; $minute <= config('trading.ml.min_history_minutes'); $minute++) {
            if (! isset($bars[$closedBefore->copy()->subMinutes($minute)->format('Y-m-d H:i')])) {
                $recentComplete = false;
            }
        }
        $minuteBars = $bars;
        $bars = array_values($bars);
        $closes = array_column($bars, 'close');
        $ranges = [];
        foreach ($bars as $i => $bar) {
            if ($i) {
                $ranges[] = max($bar['high'] - $bar['low'], abs($bar['high'] - $closes[$i - 1]), abs($bar['low'] - $closes[$i - 1]));
            }
        }
        $atr = $ranges ? array_sum(array_slice($ranges, -14)) / count(array_slice($ranges, -14)) : null;
        if ($atr !== null && $atr < 1e-12) {
            $atr = null;
        }
        $entry = ($signal->direction === 'BUY' ? $quote->ask : $quote->bid) + ($signal->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$signal->symbol.'.slippage');
        $distance = abs($entry - $signal->stop_loss);
        $sign = $signal->direction === 'BUY' ? 1 : -1;
        $features = ['direction_buy' => $signal->direction === 'BUY' ? 1 : 0, 'spread_r' => $distance > 0 ? ($quote->ask - $quote->bid) / $distance : null, 'reward_risk' => $distance > 0 ? abs($signal->take_profits[0] - $entry) / $distance : null, 'stop_atr' => $atr ? $distance / $atr : null, 'entry_distance_r' => $distance > 0 ? ($entry - ($signal->entry_min + $signal->entry_max) / 2) * $sign / $distance : null, 'latency_fraction' => min(1, max(0, $signal->signal_time->diffInSeconds($at, false) / config('trading.signal_max_age_seconds'))), 'trailing_plan' => ($management['trailing_target'] ?? false) ? 1 : 0, 'history_coverage' => count($bars) / config('trading.ml.history_minutes')];
        foreach ([5, 15, 30] as $minutes) {
            $lastBar = $minuteBars[$closedBefore->copy()->subMinute()->format('Y-m-d H:i')] ?? null;
            $earlierBar = $minuteBars[$closedBefore->copy()->subMinutes($minutes + 1)->format('Y-m-d H:i')] ?? null;
            $trend = $lastBar && $earlierBar && $atr ? ($lastBar['close'] - $earlierBar['close']) / $atr : null;
            $features['trend_'.$minutes] = $trend;
            $features['alignment_'.$minutes] = $trend === null ? null : $trend * $sign;
        }
        $previous = array_slice($bars, -15);
        $high = $previous ? max(array_column($previous, 'high')) : null;
        $low = $previous ? min(array_column($previous, 'low')) : null;
        $mid = ($quote->bid + $quote->ask) / 2;
        $features['range_position'] = $high !== null && $high > $low ? ($mid - $low) / ($high - $low) : null;
        $features['breakout_up'] = $high !== null && $mid > $high ? 1 : 0;
        $features['breakout_down'] = $low !== null && $mid < $low ? 1 : 0;
        $features['breakout_alignment'] = $signal->direction === 'BUY' ? $features['breakout_up'] : $features['breakout_down'];
        $gains = $losses = 0;
        $rsiCloses = array_slice($closes, -15);
        for ($i = 1; $i < count($rsiCloses); $i++) {
            $delta = $rsiCloses[$i] - $rsiCloses[$i - 1];
            $gains += max(0, $delta);
            $losses += max(0, -$delta);
        }
        $features['rsi_14'] = count($rsiCloses) === 15 ? ($losses > 0 ? 100 - 100 / (1 + $gains / $losses) : ($gains > 0 ? 100 : 50)) : null;
        $features['volatility_fraction'] = $atr && $mid > 0 ? $atr / $mid : null;
        // Session uses UTC event time, rather than today's label or a later regime tag.
        $hour = (int) $at->copy()->utc()->format('G');
        $session = $hour >= 13 && $hour < 16 ? 'overlap' : ($hour >= 7 && $hour < 16 ? 'london' : ($hour >= 13 && $hour < 22 ? 'new_york' : 'asia'));
        foreach (['asia', 'london', 'new_york', 'overlap'] as $name) {
            $features['session_'.$name] = $session === $name ? 1 : 0;
        }
        foreach (array_keys(config('trading.symbols')) as $symbol) {
            $features['symbol_'.$symbol] = $signal->symbol === $symbol ? 1 : 0;
        }

        return ['features' => $features, 'context' => ['feature_version' => self::VERSION, 'as_of' => $at->toIso8601String(), 'closed_minutes' => count($bars), 'history_complete_enough' => $recentComplete && count($bars) >= config('trading.ml.min_history_minutes'), 'last_history_at' => $ticks->last()?->recorded_at->toIso8601String(), 'hypothesis' => $features['breakout_alignment'] ? 'BREAKOUT_ALIGNED' : (($features['alignment_15'] ?? 0) > 1 ? 'TREND_ALIGNED' : (($features['alignment_15'] ?? 0) < -1 ? 'COUNTER_TREND' : 'UNDETERMINED')), 'hypothesis_note' => 'Observed association; not proof of the provider reasoning.']];
    }
}
