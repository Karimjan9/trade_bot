<?php

namespace App\Services\Analytics;

use App\Models\Backtest;
use App\Models\MarketSnapshot;
use App\Models\TelegramChannel;
use App\Services\Signals\SignalParser;

class Backtester
{
    public function run(TelegramChannel $channel, string $strategy = 'first_tp'): Backtest
    {
        $signals = $channel->signals()->whereIn('status', ['ready', 'historical'])->orderBy('signal_time')->orderBy('id')->get();
        $outcomes = [];
        $skipped = ['no_entry' => 0, 'unclosed' => 0];
        $split1 = (int) floor($signals->count() * 0.6);
        $split2 = (int) floor($signals->count() * 0.8);
        foreach ($signals as $index => $signal) {
            $ticks = MarketSnapshot::where('symbol', $signal->symbol)->where('recorded_at', '>=', $signal->signal_time)->orderBy('recorded_at')->orderBy('id')->cursor();
            $spec = config('trading.symbols.'.$signal->symbol);
            $entry = $stop = $risk = null;
            $entryTime = null;
            $mae = $mfe = 0;
            $closed = false;
            foreach ($ticks as $tick) {
                if (! $tick->market_open) {
                    continue;
                }
                $sign = $signal->direction === 'BUY' ? 1 : -1;
                if ($entry === null) {
                    if ($tick->recorded_at->gt($signal->signal_time->copy()->addSeconds(config('trading.signal_max_age_seconds')))) {
                        break;
                    }
                    $candidate = $signal->direction === 'BUY' ? $tick->ask : $tick->bid;
                    if ($candidate < $signal->entry_min || $candidate > $signal->entry_max) {
                        continue;
                    }
                    $entry = $candidate + $sign * $spec['slippage'];
                    $entryTime = $tick->recorded_at;
                    $stop = $signal->stop_loss;
                    $risk = abs($entry - $stop);
                    if ($risk <= 0 || ($entry - $stop) * $sign <= 0) {
                        $entry = null;

                        continue;
                    }
                }
                $price = $signal->direction === 'BUY' ? $tick->bid : $tick->ask;
                $r = ($price - $entry) * $sign / $risk;
                $mae = min($mae, $r);
                $mfe = max($mfe, $r);
                $targets = $signal->take_profits;
                $target = $strategy === 'last_tp' ? $targets[count($targets) - 1] : $targets[0];
                $stopHit = ($price - $stop) * $sign <= 0;
                $tpHit = ($price - $target) * $sign >= 0;
                if (! $stopHit && ! $tpHit) {
                    continue;
                }
                $exit = $stopHit ? $price - $sign * $spec['slippage'] : $target;
                $net = ($exit - $entry) * $sign * $spec['value_per_unit'] - config('trading.commission_per_lot_round_trip');
                $outcomes[] = ['signal_id' => $signal->id, 'split' => $index < $split1 ? 'train' : ($index < $split2 ? 'validation' : 'out_of_sample'), 'entry' => $entry, 'exit' => $exit, 'entered_at' => $entryTime->toIso8601String(), 'closed_at' => $tick->recorded_at->toIso8601String(), 'net_r' => round($net / ($risk * $spec['value_per_unit'] + config('trading.commission_per_lot_round_trip')), 5), 'mae_r' => round($mae, 4), 'mfe_r' => round($mfe, 4), 'reason' => $stopHit ? 'STOP_LOSS' : 'TAKE_PROFIT'];
                $closed = true;
                break;
            }
            if (! $closed) {
                $skipped[$entry === null ? 'no_entry' : 'unclosed']++;
            }
        }
        $rows = collect($outcomes);
        $splits = [];
        foreach (['train', 'validation', 'out_of_sample'] as $part) {
            $splits[$part] = app(ChannelAnalytics::class)->metrics($rows->where('split', $part)->pluck('net_r'));
        }

        return Backtest::create(['channel_id' => $channel->id, 'status' => 'COMPLETED', 'strategy' => $strategy, 'parameters' => ['entry_window_seconds' => config('trading.signal_max_age_seconds'), 'commission_per_lot' => config('trading.commission_per_lot_round_trip'), 'split' => [0.6, 0.2, 0.2], 'parser' => SignalParser::VERSION], 'results' => ['metrics' => app(ChannelAnalytics::class)->metrics($rows->pluck('net_r')), 'splits' => $splits, 'skipped' => $skipped, 'signal_count' => $signals->count(), 'trades' => $outcomes, 'method' => 'Independent bid/ask tick replay; costs included. Fixed chronological split, no parameter fitting. Not a portfolio backtest or walk-forward optimization.']]);
    }
}
