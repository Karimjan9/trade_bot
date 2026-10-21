<?php

namespace App\Services\Trading;

use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\TradeEvent;

class Portfolio
{
    public function summary(): array
    {
        $risk = RiskProfile::current();
        $trades = PaperTrade::where('mode', 'paper')->get();
        $open = $trades->where('status', 'open');
        $balance = $risk->starting_balance + $trades->sum('realized_pnl');
        $floating = $this->floating($open);
        $running = (float) $risk->starting_balance;
        $peak = max($running, (float) $risk->peak_equity);
        foreach ($trades->where('status', 'closed')->sortBy('closed_at') as $trade) {
            $running += $trade->realized_pnl;
            $peak = max($peak, $running);
        }
        $equity = $balance + $floating;
        $realizations = TradeEvent::whereIn('trade_id', $trades->pluck('id'))->whereIn('type', ['OPENED', 'ENTRY_ADDED', 'PARTIAL_CLOSE', 'CLOSED'])->get();
        $daily = $realizations->filter(fn ($e) => $e->occurred_at->gte(now()->startOfDay()))->sum(fn ($e) => $e->payload['pnl'] ?? 0);
        $weekly = $realizations->filter(fn ($e) => $e->occurred_at->gte(now()->startOfWeek()))->sum(fn ($e) => $e->payload['pnl'] ?? 0);

        return ['balance' => $balance, 'equity' => $equity, 'floating' => $floating, 'daily_pnl' => $daily, 'weekly_pnl' => $weekly, 'drawdown_pct' => $peak > 0 ? max(0, ($peak - $equity) / $peak * 100) : 100, 'open_risk' => $open->sum(fn ($t) => $t->exposureRisk()), 'open_count' => $open->count()];
    }

    public function floating(iterable $trades): float
    {
        $floating = 0;
        foreach ($trades as $trade) {
            $quote = MarketSnapshot::latestCurrent($trade->symbol);
            if ($quote) {
                $exit = $trade->direction === 'BUY' ? $quote->bid : $quote->ask;
                $floating += ($exit - $trade->entry_price) * ($trade->direction === 'BUY' ? 1 : -1) * $trade->volume * $trade->value_per_unit * $trade->remaining_fraction;
            }
        }

        return $floating;
    }
}
