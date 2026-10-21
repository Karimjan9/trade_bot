<?php

namespace App\Services\Trading;

use App\Models\Agent;
use App\Models\ChannelStrategy;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TradeEvent;
use App\Services\Analytics\ChannelUnits;
use Carbon\CarbonImmutable;

class PaperExecution
{
    // Called under the singleton risk-profile transaction lock.
    public function open(Signal $signal, Agent $agent, MarketSnapshot $quote, array $approval, string $mode = 'paper', string $strategy = 'market', array $management = []): PaperTrade
    {
        $spec = config('trading.symbols.'.$signal->symbol);
        $commission = $approval['volume'] * config('trading.commission_per_lot_round_trip');
        $legs = $approval['entry_legs'] ?? null;
        if ($legs !== null) {
            $fill = $signal->direction === 'BUY' ? $quote->ask : $quote->bid;
            foreach ($legs as &$leg) {
                if (app(EntryPlans::class)->eligible($management['entry_config'], $leg['limit'], $signal->direction, $fill)) {
                    $leg = array_merge($leg, ['status' => 'FILLED', 'price' => $approval['entry'], 'at' => $quote->recorded_at->toIso8601String()]);
                }
            }
            unset($leg);
            $management['entry_spec'] = $spec;
        }
        $trade = PaperTrade::create([
            'signal_id' => $signal->id, 'channel_id' => $signal->channel_id, 'agent_id' => $agent->id, 'snapshot_id' => $quote->id,
            'mode' => $mode, 'strategy' => $strategy, 'symbol' => $signal->symbol, 'direction' => $signal->direction,
            'entry_price' => $approval['entry'], 'stop_loss' => $signal->stop_loss, 'initial_stop' => $signal->stop_loss, 'take_profit' => ($management['trailing_target'] ?? false) ? null : $signal->take_profits[0],
            'volume' => $approval['volume'], 'value_per_unit' => $spec['value_per_unit'], 'risk_amount' => $approval['risk_amount'], 'commission' => $commission,
            'realized_pnl' => -$commission, 'opened_at' => $quote->recorded_at,
            'entry_legs' => $legs,
            'management_policy' => $management ?: null, 'expires_at' => $management ? $quote->recorded_at->copy()->addSeconds($management['horizon_seconds']) : null,
        ]);
        $this->event($trade, 'OPENED', ['entry' => $trade->entry_price, 'volume' => $trade->volume, 'pnl' => -$commission, 'risk_amount' => $trade->risk_amount, 'channel_management' => $management], $quote->recorded_at);

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
            $r = $trade->entry_legs === null ? ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) / abs($trade->entry_price - $trade->initial_stop)
                : ($trade->realized_pnl + ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) * $trade->volume * $trade->value_per_unit * $trade->remaining_fraction) / max(1e-9, $trade->risk_amount);
            $trade->update(['mae_r' => min($trade->mae_r, $r), 'mfe_r' => max($trade->mfe_r, $r)]);
            $stopHit = $trade->direction === 'BUY' ? $price <= $trade->stop_loss : $price >= $trade->stop_loss;
            $tpHit = $trade->take_profit !== null && ($trade->direction === 'BUY' ? $price >= $trade->take_profit : $price <= $trade->take_profit);
            if ($stopHit || $tpHit) {
                // Stop gaps use observed worse price; take profit fills at the target.
                $exit = $stopHit ? $price : $trade->take_profit;
                if ($stopHit) {
                    $exit -= ($trade->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$trade->symbol.'.slippage');
                }
                $protected = ($trade->stop_loss - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) >= -1e-9;
                $this->closeFraction($trade, 1, $exit, $stopHit ? ($protected ? 'PROFIT_LOCK_EXIT' : 'STOP_LOSS') : 'TAKE_PROFIT', $quote->recorded_at);

                continue;
            }
            if ($trade->expires_at && $quote->recorded_at->gte($trade->expires_at)) {
                $exit = $price - ($trade->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$trade->symbol.'.slippage');
                $this->closeFraction($trade, 1, $exit, 'POLICY_TIME_EXIT', $quote->recorded_at);

                continue;
            }
            $this->addEntries($trade, $quote);
            if ($trade->entry_legs !== null) {
                $move = ($trade->realized_pnl + ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) * $trade->volume * $trade->value_per_unit * $trade->remaining_fraction) / max(1e-9, $trade->risk_amount);
                $trade->update(['mae_r' => min($trade->mae_r, $move), 'mfe_r' => max($trade->mfe_r, $move)]);
            }
            $policy = $trade->management_policy;
            $sign = $trade->direction === 'BUY' ? 1 : -1;
            if (isset($policy['save_trigger_pips'], $policy['save_fraction'], $policy['pip_size']) && ! ($policy['save_done'] ?? false) && ($price - $trade->entry_price) * $sign / $policy['pip_size'] + 1e-8 >= $policy['save_trigger_pips']) {
                $exit = $price - $sign * config('trading.symbols.'.$trade->symbol.'.slippage');
                if (($exit - $trade->entry_price) * $sign * $trade->volume * $trade->value_per_unit > $trade->commission) {
                    $this->closeFraction($trade, $policy['save_fraction'], $exit, 'POLICY_SAVE', $quote->recorded_at);
                    $policy['save_done'] = true;
                    $changes = ['management_policy' => $policy];
                    if (($trade->entry_price - $trade->stop_loss) * $sign > 0) {
                        $changes['stop_loss'] = $trade->entry_price;
                    }
                    $trade->update($changes);
                    $this->event($trade, 'POLICY_SAVE', ['strategy_id' => $policy['strategy_id'], 'fraction' => $policy['save_fraction'], 'stop' => $trade->stop_loss], $quote->recorded_at);
                }
            }
            if (isset($policy['pip_size'], $policy['lock_step_pips'])) {
                $lock = app(ProfitLock::class)->nextStop($trade->direction, $trade->entry_price, $price, $trade->stop_loss, $policy['pip_size'], $policy['lock_step_pips']);
                if ($lock) {
                    $this->cancelPending($trade, 'PROFIT_PROTECTED', $quote->recorded_at);
                    $trade->update(['stop_loss' => $lock['stop']]);
                    $this->event($trade, 'PROFIT_LOCK', $lock + ['policy_id' => $policy['policy_id'] ?? null, 'strategy_id' => $policy['strategy_id'] ?? null], $quote->recorded_at);
                }
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
            if ($action['requires_profit'] ?? false) {
                $sign = $trade->direction === 'BUY' ? 1 : -1;
                $net = ($price - $sign * config('trading.symbols.'.$trade->symbol.'.slippage') - $trade->entry_price) * $sign * $trade->volume * $trade->value_per_unit - $trade->commission;
                if ($net <= 0) {
                    $this->event($trade, 'UPDATE_REJECTED', ['reason' => 'SAVE_REQUIRES_NET_PROFIT', 'action' => $action], now());

                    continue;
                }
            }
            if (in_array($action['type'], ['CLOSE_ALL', 'PARTIAL_CLOSE'])) {
                $this->closeFraction($trade, $action['fraction'] ?? 1, $price - ($trade->direction === 'BUY' ? 1 : -1) * config('trading.symbols.'.$trade->symbol.'.slippage'), $action['type'], now());
            } elseif (in_array($action['type'], ['BREAK_EVEN', 'MOVE_SL'])) {
                $stop = $action['type'] === 'BREAK_EVEN' ? $trade->entry_price : $action['price'];
                $valid = $trade->direction === 'BUY' ? ($stop >= $trade->stop_loss && $stop < $price) : ($stop <= $trade->stop_loss && $stop > $price);
                if ($valid) {
                    $this->cancelPending($trade, 'STOP_CHANGED', now());
                    $trade->update(['stop_loss' => $stop]);
                }
                $this->event($trade, $valid ? $action['type'] : 'UPDATE_REJECTED', ['stop' => $stop, 'reason' => $valid ? 'STOP_TIGHTENED' : 'STOP_MUST_REDUCE_RISK'], now());
            } elseif ($action['type'] === 'PROFIT_LOCK') {
                $pip = $trade->management_policy['pip_size'] ?? app(ChannelUnits::class)->pipSize($signal->channel, $trade->symbol);
                $stop = null;
                if (isset($action['target_index'])) {
                    $stop = $signal->take_profits[$action['target_index']] ?? null;
                } elseif (isset($action['locked_pips']) && $pip) {
                    $stop = $trade->entry_price + ($trade->direction === 'BUY' ? 1 : -1) * $action['locked_pips'] * $pip;
                } elseif (($action['use_channel_policy'] ?? false) && $trade->management_policy) {
                    $stop = app(ProfitLock::class)->nextStop($trade->direction, $trade->entry_price, $price, $trade->stop_loss, $pip, $trade->management_policy['lock_step_pips'])['stop'] ?? null;
                }
                $sign = $trade->direction === 'BUY' ? 1 : -1;
                $valid = $stop !== null && ($stop - $trade->entry_price) * $sign >= 0 && ($stop - $trade->stop_loss) * $sign > 1e-9 && ($price - $stop) * $sign > 0;
                if ($valid) {
                    $this->cancelPending($trade, 'PROFIT_PROTECTED', now());
                    $trade->update(['stop_loss' => $stop]);
                }
                $this->event($trade, $valid ? 'PROFIT_LOCK' : 'UPDATE_REJECTED', ['stop' => $stop, 'action' => $action, 'reason' => $valid ? 'PROFIT_PROTECTED' : 'PROFIT_LOCK_NOT_APPLICABLE'], now());
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
        $this->cancelPending($trade, $reason, $time);
        $closed = $trade->remaining_fraction * min(1, max(0, $fraction));
        $pnl = ($price - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) * $trade->volume * $trade->value_per_unit * $closed;
        $remaining = max(0, $trade->remaining_fraction - $closed);
        $trade->update(['remaining_fraction' => $remaining, 'realized_pnl' => $trade->realized_pnl + $pnl, 'status' => $remaining < 1e-8 ? 'closed' : 'open', 'exit_price' => $price, 'close_reason' => $reason, 'closed_at' => $remaining < 1e-8 ? $time : null]);
        $this->event($trade, $remaining < 1e-8 ? 'CLOSED' : 'PARTIAL_CLOSE', ['fraction' => $closed, 'price' => $price, 'pnl' => $pnl, 'reason' => $reason], $time);
    }

    private function addEntries(PaperTrade $trade, MarketSnapshot $quote): void
    {
        if (! collect($trade->entry_legs ?? [])->contains('status', 'PENDING')) {
            return;
        }
        $plan = $trade->management_policy['entry_config'];
        if ($quote->recorded_at->gt(CarbonImmutable::parse($plan['deadline']))) {
            $this->cancelPending($trade, 'ENTRY_WINDOW_EXPIRED', $quote->recorded_at);

            return;
        }
        $rules = RiskProfile::current();
        $channel = $trade->channel;
        $latestPolicy = ChannelStrategy::where('channel_id', $trade->channel_id)->where('symbol', $trade->symbol)->where('direction', $trade->direction)->whereIn('status', ['PAPER_ELIGIBLE', 'UNPROFITABLE', 'SHADOW_ONLY', 'RECOVERY_SHADOW'])->latest('id')->first();
        $account = app(Portfolio::class)->summary();
        $limits = app(ChannelRisk::class)->limits($channel, $rules);
        $open = PaperTrade::where('mode', 'paper')->where('status', 'open')->get();
        $blocked = $rules->emergency_stop || ! $channel->enabled || in_array($channel->trade_state, ['EXCLUDED', 'MONITOR_ONLY']) || $trade->signal->status !== 'ready'
            || $trade->remaining_fraction < 1 || abs($trade->initial_stop - $trade->stop_loss) > 1e-8
            || ($latestPolicy && $latestPolicy->status !== 'PAPER_ELIGIBLE')
            || in_array($channel->profile?->drift, ['DETECTED', 'SEVERE'])
            || app(ChannelRisk::class)->reasons($channel, $rules) !== []
            || $account['drawdown_pct'] >= $rules->max_drawdown_pct
            || -$rules->starting_balance * $rules->max_daily_loss_pct / 100 >= $account['daily_pnl'] + min(0, $account['floating'])
            || -$rules->starting_balance * $rules->max_weekly_loss_pct / 100 >= $account['weekly_pnl'] + min(0, $account['floating'])
            || $trade->exposureRisk() > max(0, $account['equity']) * $limits->max_trade_risk_pct / 100 + 1e-8;
        foreach (['max_channel_risk_pct' => $open->where('channel_id', $channel->id), 'max_symbol_risk_pct' => $open->where('symbol', $trade->symbol), 'max_portfolio_risk_pct' => $open] as $key => $exposures) {
            $blocked = $blocked || $exposures->sum(fn ($t) => $t->exposureRisk()) > max(0, $account['equity']) * $limits->$key / 100 + 1e-8;
        }
        $spec = config('trading.symbols.'.$trade->symbol);
        if ($blocked || $spec !== $trade->management_policy['entry_spec'] || $quote->regime === 'NEWS_SHOCK') {
            $this->cancelPending($trade, 'ENTRY_RISK_RECHECK_FAILED', $quote->recorded_at);

            return;
        }
        $sign = $trade->direction === 'BUY' ? 1 : -1;
        $price = $sign === 1 ? $quote->ask : $quote->bid;
        if (($quote->ask - $quote->bid) / max(1e-9, abs($price - $trade->stop_loss)) > $rules->max_spread_r) {
            return;
        }
        $legs = $trade->entry_legs;
        foreach ($legs as &$leg) {
            if ($leg['status'] !== 'PENDING' || ! app(EntryPlans::class)->eligible($plan, $leg['limit'], $trade->direction, $price)) {
                continue;
            }
            $fill = $price + $sign * $spec['slippage'];
            $commission = $leg['volume'] * config('trading.commission_per_lot_round_trip');
            $entry = ($trade->entry_price * $trade->volume + $fill * $leg['volume']) / ($trade->volume + $leg['volume']);
            $trade->fill(['entry_price' => $entry, 'volume' => $trade->volume + $leg['volume'], 'commission' => $trade->commission + $commission, 'realized_pnl' => $trade->realized_pnl - $commission]);
            $leg = array_merge($leg, ['status' => 'FILLED', 'price' => $fill, 'at' => $quote->recorded_at->toIso8601String()]);
            $this->event($trade, 'ENTRY_ADDED', ['entry' => $fill, 'volume' => $leg['volume'], 'average_entry' => $entry, 'pnl' => -$commission, 'reserved_risk' => $leg['reserved_risk']], $quote->recorded_at);
        }
        unset($leg);
        $trade->entry_legs = $legs;
        $trade->save();
    }

    private function cancelPending(PaperTrade $trade, string $reason, $time): void
    {
        $legs = $trade->entry_legs;
        if ($legs === null || ! collect($legs)->contains('status', 'PENDING')) {
            return;
        }
        foreach ($legs as &$leg) {
            if ($leg['status'] === 'PENDING') {
                $leg['status'] = 'CANCELLED';
            }
        }
        unset($leg);
        $trade->update(['entry_legs' => $legs]);
        $this->event($trade, 'ENTRIES_CANCELLED', ['reason' => $reason], $time);
    }

    private function event(PaperTrade $trade, string $type, array $payload, $time): void
    {
        TradeEvent::create(['trade_id' => $trade->id, 'type' => $type, 'payload' => $payload, 'occurred_at' => $time]);
    }
}
