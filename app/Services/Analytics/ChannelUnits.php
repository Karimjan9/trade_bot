<?php

namespace App\Services\Analytics;

use App\Models\TelegramChannel;

class ChannelUnits
{
    public function pipSize(TelegramChannel $channel, string $symbol): ?float
    {
        $value = $channel->research_settings[$symbol]['pip_size'] ?? config('trading.symbols.'.$symbol.'.pip_size');

        return is_numeric($value) && $value > 0 ? (float) $value : null;
    }

    public function horizon(TelegramChannel $channel, string $symbol): int
    {
        return (int) ($channel->research_settings[$symbol]['horizon_seconds'] ?? config('trading.research.horizon_seconds'));
    }
}
