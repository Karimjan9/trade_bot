<?php

namespace App\Services\Trading;

class ProfitLock
{
    public function nextStop(string $direction, float $entry, float $price, float $currentStop, float $pipSize, float $stepPips): ?array
    {
        if ($pipSize <= 0 || $stepPips <= 0) {
            return null;
        }
        $sign = $direction === 'BUY' ? 1 : -1;
        $move = ($price - $entry) * $sign / $pipSize;
        $level = (int) floor(($move + 1e-8) / $stepPips);
        if ($level < 1) {
            return null;
        }
        $locked = ($level - 1) * $stepPips;
        $stop = $entry + $sign * $locked * $pipSize;
        if (($stop - $currentStop) * $sign <= 1e-9 || ($price - $stop) * $sign <= 0) {
            return null;
        }

        return ['stop' => $stop, 'trigger_pips' => $level * $stepPips, 'locked_pips' => $locked];
    }
}
