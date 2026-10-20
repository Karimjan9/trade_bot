<?php

namespace App\Services\Trading;

use App\Models\Agent;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\Signal;
use App\Models\TradeEvent;

class PaperExecution
{
    // Called under the singleton risk-profile transaction lock.
    public function open(Signal $signal, Agent $agent, MarketSnapshot $quote, array $approval, string $mode = 'paper', string $strategy = 'market'): PaperTrade
    {
        $spec = config('trading.symbols.'.$signal->symbol);
        $commission = $approval['volume'] * config('trading.commission_per_lot_round_trip');
        $trade = PaperTrade::create([
            'signal_id' => $signal->id, 'channel_id' => $signal->channel_id, 'agent_id' => $agent->id, 'snapshot_id' => $quote->id,
            'mode' => $mode, 'strategy' => $strategy, 'symbol' => $signal->symbol, 'direction' => $signal->direction,
            'entry_price' => $approval['entry'], 'stop_loss' => $signal->stop_loss, 'initial_stop' => $signal->stop_loss, 'take_profit' => $signal->take_profits[0],
            'volume' => $approval['volume'], 'value_per_unit' => $spec['value_per_unit'], 'risk_amount' => $approval['risk_amount'], 'commission' => $commission,
            'realized_pnl' => -$commission, 'opened_at' => $quote->recorded_at,
        ]);
        $this->event($trade, 'OPENED', ['entry' => $trade->entry_price, 'volume' => $trade->volume, 'pnl' => -$commission, 'risk_amount' => $trade->risk_amount], $quote->recorded_at);

        return $trade;
    }

    public function mark(MarketSnapshot $quote): void
    {
        if ($quote->is_historical) {
            return;
        }
        foreach (PaperTrade::where('status', 'open')->where('symbol', $quote->symbol)->lockForUpdate()->get() as $trade) {
            if ($quote->recorded_at->lt($trade->opened_at)) {
                continue;
            }
            $price = $trade->direction === 'BUY' ? $quote->bid : $quote->ask;
            $r = ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) / abs($trade->entry_price - $trade->initial_stop);
            $trade->update(['mae_r' => min($trade->mae_r, $r), 'mfe_r' => max($trade->mfe_r, $r)]);
            $stopHit = $trade->direction === 'BUY' ? $price <= $trade->stop_loss : $price >= $trade->stop_loss;
            $tpHit = $trade->direction === 'BUY' ? $price >= $trade->take_profit : $price <= $trade->take_profit;
            if ($stopHit || $tpHit) {
                // Stop gaps use observed worse price; take profit fills at the target.
                $exit = $stopHit ? $price : $trade->take_profit;
                if ($stopHit) {
                    $exit -= ($trade->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$trade->symbol.'.slippage');
                }
                $this->closeFraction($trade, 1, $exit, $stopHit ? 'STOP_LOSS' : 'TAKE_PROFIT', $quote->recorded_at);
            }
        }
    }

    public function apply(Signal $signal, array $action): void
    {
        foreach ($signal->trades()->where('status', 'open')->lockForUpdate()->get() as $trade) {
            $quote = MarketSnapshot::latestCurrent($trade->symbol);
            if (! $quote || ! $quote->market_open || $quote->recorded_at->lt(now()->subSeconds(config('trading.quote_max_age_seconds'))) || $quote->recorded_at->gt(now()->addSeconds(5))) {
                $this->event($trade, 'UPDATE_REJECTED', ['reason' => 'FRESH_QUOTE_REQUIRED', 'action' => $action], now());

                continue;
            }
            $price = $trade->direction === 'BUY' ? $quote->bid : $quote->ask;
            if (in_array($action['type'], ['CLOSE_ALL', 'PARTIAL_CLOSE'])) {
                $this->closeFraction($trade, $action['fraction'] ?? 1, $price - ($trade->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$trade->symbol.'.slippage'), $action['type'], now());
            } elseif (in_array($action['type'], ['BREAK_EVEN', 'MOVE_SL'])) {
                $stop = $action['type'] === 'BREAK_EVEN' ? $trade->entry_price : $action['price'];
                $valid = $trade->direction === 'BUY' ? ($stop >= $trade->stop_loss && $stop < $price) : ($stop <= $trade->stop_loss && $stop > $price);
                if ($valid) {
                    $trade->update(['stop_loss' => $stop]);
                }
                $this->event($trade, $valid ? $action['type'] : 'UPDATE_REJECTED', ['stop' => $stop, 'reason' => $valid ? 'STOP_TIGHTENED' : 'STOP_MUST_REDUCE_RISK'], now());
            } elseif ($action['type'] === 'MOVE_TP') {
                $valid = $trade->direction === 'BUY' ? $action['price'] > $price : $action['price'] < $price;
                if ($valid) {
                    $trade->update(['take_profit' => $action['price']]);
                }
                $this->event($trade, $valid ? 'MOVE_TP' : 'UPDATE_REJECTED', $action, now());
            }
        }
    }

    public function closeFraction(PaperTrade $trade, float $fraction, float $price, string $reason, $time): void
    {
        $closed = $trade->remaining_fraction * min(1, max(0, $fraction));
        $pnl = ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) * $trade->volume * $trade->value_per_unit * $closed;
        $remaining = max(0, $trade->remaining_fraction - $closed);
        $trade->update(['remaining_fraction' => $remaining, 'realized_pnl' => $trade->realized_pnl + $pnl, 'status' => $remaining < 1e-8 ? 'closed' : 'open', 'exit_price' => $price, 'close_reason' => $reason, 'closed_at' => $remaining < 1e-8 ? $time : null]);
        $this->event($trade, $remaining < 1e-8 ? 'CLOSED' : 'PARTIAL_CLOSE', ['fraction' => $closed, 'price' => $price, 'pnl' => $pnl, 'reason' => $reason], $time);
    }

    private function event(PaperTrade $trade, string $type, array $payload, $time): void
    {
        TradeEvent::create(['trade_id' => $trade->id, 'type' => $type, 'payload' => $payload, 'occurred_at' => $time]);
    }
}
