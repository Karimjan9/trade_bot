<?php

namespace App\Services\Trading;

use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;

class RiskEngine
{
    public function evaluate(Signal $signal, MarketSnapshot $quote, RiskProfile $rules, float $riskMultiplier = 1, array $management = []): array
    {
        $channelReasons = app(ChannelRisk::class)->reasons($signal->channel, $rules);
        $rules = app(ChannelRisk::class)->limits($signal->channel, $rules);
        $account = app(Portfolio::class)->summary();
        $reasons = $channelReasons;
        $entry = $signal->direction === 'BUY' ? $quote->ask : $quote->bid;
        $spec = config('trading.symbols.'.$signal->symbol);
        $entry += ($signal->direction === 'BUY' ? 1 : -1) * ($spec['slippage'] ?? 0);
        $distance = abs($entry - $signal->stop_loss);
        $target = $signal->take_profits[0];
        if ($rules->emergency_stop) {
            $reasons[] = 'EMERGENCY_STOP';
        }
        if (! $signal->channel->enabled) {
            $reasons[] = 'CHANNEL_DISABLED';
        }
        if ($signal->channel->trade_state === 'EXCLUDED') {
            $reasons[] = 'CHANNEL_UNPROFITABLE_MONITOR_ONLY';
        }
        if ($signal->channel->trade_state === 'MONITOR_ONLY') {
            $reasons[] = 'CHANNEL_RESEARCH_MONITOR_ONLY';
        }
        if ($signal->status !== 'ready') {
            $reasons[] = 'SIGNAL_NOT_READY';
        }
        if (! $spec) {
            $reasons[] = 'UNSUPPORTED_SYMBOL';
        }
        if ($quote->is_historical || ! $quote->market_open) {
            $reasons[] = 'MARKET_UNAVAILABLE';
        }
        if ($quote->recorded_at->lt(now()->subSeconds(config('trading.quote_max_age_seconds'))) || $quote->recorded_at->gt(now()->addSeconds(5))) {
            $reasons[] = 'STALE_OR_FUTURE_QUOTE';
        }
        if ($signal->signal_time->lt(now()->subSeconds(config('trading.signal_max_age_seconds'))) || $signal->signal_time->gt(now()->addSeconds(5))) {
            $reasons[] = 'SIGNAL_EXPIRED_OR_FUTURE';
        }
        if ($entry < $signal->entry_min - ($spec['slippage'] ?? 0) || $entry > $signal->entry_max + ($spec['slippage'] ?? 0)) {
            $reasons[] = 'WAIT_RETRACEMENT';
        }
        if ($distance <= 0 || ($signal->direction === 'BUY' ? ($signal->stop_loss >= $entry || $target <= $entry) : ($signal->stop_loss <= $entry || $target >= $entry))) {
            $reasons[] = 'INVALID_STOPS';
        }
        $rr = $distance > 0 ? abs($target - $entry) / $distance : 0;
        if ($rr < $rules->min_reward_risk) {
            $reasons[] = 'LOW_REWARD_RISK';
        }
        if ($distance > 0 && ($quote->ask - $quote->bid) / $distance > $rules->max_spread_r) {
            $reasons[] = 'SPREAD_TOO_HIGH';
        }
        if ($account['equity'] <= 0) {
            $reasons[] = 'NO_EQUITY';
        }
        $base = max(1, $rules->starting_balance);
        if (-$base * $rules->max_daily_loss_pct / 100 >= $account['daily_pnl'] + min(0, $account['floating'])) {
            $reasons[] = 'DAILY_LOSS_LIMIT';
        }
        if (-$base * $rules->max_weekly_loss_pct / 100 >= $account['weekly_pnl'] + min(0, $account['floating'])) {
            $reasons[] = 'WEEKLY_LOSS_LIMIT';
        }
        if ($account['drawdown_pct'] >= $rules->max_drawdown_pct) {
            $reasons[] = 'DRAWDOWN_LIMIT';
        }
        $open = PaperTrade::where('mode', 'paper')->where('status', 'open')->get();
        if ($open->count() >= $rules->max_positions) {
            $reasons[] = 'POSITION_LIMIT';
        }
        // One exposure per symbol, including opposite directions and copied channel signals.
        if ($open->contains('symbol', $signal->symbol)) {
            $reasons[] = 'EXISTING_SYMBOL_EXPOSURE';
        }
        if (PaperTrade::where('signal_id', $signal->id)->where('mode', 'paper')->exists()) {
            $reasons[] = 'DUPLICATE_TRADE';
        }
        $budget = max(0, $account['equity']) * $rules->max_trade_risk_pct / 100 * min(1, max(0, $riskMultiplier)) * min(1, max(0, $management['risk_multiplier'] ?? 1));
        $perLotLoss = $distance * ($spec['value_per_unit'] ?? 0) + config('trading.commission_per_lot_round_trip');
        $step = $spec['volume_step'] ?? 0.01;
        $volume = $perLotLoss > 0 ? floor(($budget / $perLotLoss + 1e-10) / $step) * $step : 0;
        $volume = round(min($volume, $spec['volume_max'] ?? 0), 8);
        $amount = $volume * $perLotLoss;
        $legs = null;
        if (isset($management['entry_config'])) {
            $allocation = app(EntryPlans::class)->allocate($management['entry_config'], $signal->direction, $signal->direction === 'BUY' ? $quote->ask : $quote->bid, $budget, $spec, config('trading.commission_per_lot_round_trip'));
            $legs = $allocation['legs'];
            $amount = $allocation['risk_amount'];
            $fill = $signal->direction === 'BUY' ? $quote->ask : $quote->bid;
            $volume = collect($legs)->filter(fn ($leg) => app(EntryPlans::class)->eligible($management['entry_config'], $leg['limit'], $signal->direction, $fill))->sum('volume');
            if ($allocation['reason']) {
                $reasons[] = $allocation['reason'];
            } elseif ($volume <= 0) {
                $reasons[] = 'WAIT_ENTRY_LEVEL';
            }
        }
        if ($volume < ($spec['volume_min'] ?? 0.01) && $legs === null) {
            $reasons[] = 'BELOW_MINIMUM_VOLUME';
        }
        foreach (['max_channel_risk_pct' => $open->where('channel_id', $signal->channel_id), 'max_symbol_risk_pct' => $open->where('symbol', $signal->symbol), 'max_portfolio_risk_pct' => $open] as $limit => $exposures) {
            if ($exposures->sum(fn ($t) => $t->exposureRisk()) + $amount > max(0, $account['equity']) * $rules->$limit / 100 + 1e-8) {
                $reasons[] = strtoupper($limit);
            }
        }
        if ($quote->regime === 'NEWS_SHOCK') {
            $reasons[] = 'NEWS_SHOCK';
        }

        return ['approved' => $reasons === [], 'reasons' => $reasons, 'entry' => $entry, 'volume' => $volume, 'risk_amount' => $amount, 'reward_risk' => $rr, 'equity' => $account['equity'], 'budget' => $budget, 'entry_legs' => $legs, 'channel_limits' => $signal->channel->risk_settings];
    }
}
