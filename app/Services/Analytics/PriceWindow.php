<?php

namespace App\Services\Analytics;

use App\Models\MarketSnapshot;
use Carbon\CarbonInterface;

class PriceWindow
{
    public function read(string $symbol, CarbonInterface $start, CarbonInterface $end): array
    {
        $ticks = MarketSnapshot::where('symbol', $symbol)->where('market_open', true)->whereBetween('recorded_at', [$start, $end])->orderBy('recorded_at')->orderBy('id')->limit(100001)->get();
        $previous = $start;
        $maxGap = 0;
        foreach ($ticks as $tick) {
            $maxGap = max($maxGap, abs($previous->diffInSeconds($tick->recorded_at)));
            $previous = $tick->recorded_at;
        }
        $maxGap = max($maxGap, abs($previous->diffInSeconds($end)));

        return ['ticks' => $ticks, 'count' => $ticks->count(), 'max_gap_seconds' => $maxGap, 'covered' => $end->gte($start) && $ticks->count() > 1 && $ticks->count() <= 100000 && $maxGap <= config('trading.research.max_quote_gap_seconds')];
    }
}
