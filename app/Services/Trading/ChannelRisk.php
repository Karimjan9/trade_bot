<?php

namespace App\Services\Trading;

use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\TelegramChannel;
use App\Models\TradeEvent;

class ChannelRisk
{
    public function limits(TelegramChannel $channel, RiskProfile $global): RiskProfile
    {
        $rules = clone $global;
        foreach (['max_trade_risk_pct', 'max_channel_risk_pct'] as $key) {
            if (isset($channel->risk_settings[$key])) {
                $rules->$key = min($rules->$key, $channel->risk_settings[$key]);
            }
        }

        return $rules;
    }

    public function reasons(TelegramChannel $channel, RiskProfile $global): array
    {
        $settings = $channel->risk_settings ?? [];
        foreach (['max_daily_loss_pct', 'max_weekly_loss_pct', 'max_drawdown_pct'] as $key) {
            $settings[$key] = min($global->$key, $settings[$key] ?? $global->$key);
        }
        $trades = PaperTrade::where('mode', 'paper')->where('channel_id', $channel->id)->get();
        $events = TradeEvent::whereIn('trade_id', $trades->pluck('id'))->whereIn('type', ['OPENED', 'ENTRY_ADDED', 'PARTIAL_CLOSE', 'CLOSED'])->orderBy('occurred_at')->orderBy('id')->get();
        $floating = app(Portfolio::class)->floating($trades->where('status', 'open'));
        $running = $peak = (float) $global->starting_balance;
        foreach ($events as $event) {
            $running += $event->payload['pnl'] ?? 0;
            $peak = max($peak, $running);
        }
        $reasons = [];
        foreach (['max_daily_loss_pct' => now()->startOfDay(), 'max_weekly_loss_pct' => now()->startOfWeek()] as $key => $start) {
            $pnl = $events->filter(fn ($e) => $e->occurred_at->gte($start))->sum(fn ($e) => $e->payload['pnl'] ?? 0) + min(0, $floating);
            if (isset($settings[$key]) && $pnl <= -$global->starting_balance * min($settings[$key], $global->$key) / 100) {
                $reasons[] = 'CHANNEL_'.strtoupper($key);
            }
        }
        if (isset($settings['max_drawdown_pct']) && ($peak - ($running + $floating)) / max(1, $global->starting_balance) * 100 >= min($settings['max_drawdown_pct'], $global->max_drawdown_pct)) {
            $reasons[] = 'CHANNEL_DRAWDOWN_LIMIT';
        }

        return $reasons;
    }
}
