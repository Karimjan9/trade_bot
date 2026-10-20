<?php

namespace App\Services\Signals;

class SignalParser
{
    public const VERSION = 'rules-1.0';

    public function parse(string $raw): array
    {
        $text = mb_strtoupper(str_replace(['–', '—', '−'], '-', $raw));
        $actions = [];
        if (preg_match('/\b(?:CLOSE\s+(?:ALL|FULL)|CLOSE\s+TRADE|EXIT\s+(?:ALL|NOW))\b/', $text)) {
            $actions[] = ['type' => 'CLOSE_ALL'];
        } elseif (preg_match('/\b(?:CLOSE\s+(\d+(?:\.\d+)?)\s*%|CLOSE\s+HALF\b|PARTIAL\s+CLOSE\b)/', $text, $m)) {
            $actions[] = ['type' => 'PARTIAL_CLOSE', 'fraction' => isset($m[1]) && $m[1] !== '' ? min(1, (float) $m[1] / 100) : 0.5];
        }
        if (preg_match('/\b(?:BREAK\s*EVEN|BREAKEVEN|(?:SL|STOP)\s+(?:TO\s+)?BE)\b/', $text)) {
            $actions[] = ['type' => 'BREAK_EVEN'];
        }
        foreach (['SL' => 'MOVE_SL', 'TP' => 'MOVE_TP'] as $tag => $type) {
            if (preg_match('/\b(?:MOVE|SET|UPDATE)\s+'.$tag.'\s*(?:TO|:|=|@)?\s*(\d+(?:\.\d+)?)/', $text, $m)) {
                $actions[] = ['type' => $type, 'price' => (float) $m[1]];
            }
        }
        if (preg_match('/\bCANCEL\s+(?:SIGNAL|ORDER|TRADE)\b/', $text)) {
            $actions[] = ['type' => 'CANCEL_SIGNAL'];
        }
        if ($actions) {
            return ['type' => 'SIGNAL_UPDATE', 'actions' => $actions, 'confidence' => 0.95];
        }
        if (preg_match('/\b(?:TP\d*\s+HIT|PROFIT\s+BOOKED|PIPS\s+PROFIT|STOP\s+HIT|SL\s+HIT)\b/', $text)) {
            return ['type' => 'RESULT_REPORT', 'actions' => []];
        }
        if (! preg_match('/\b(BUY|SELL)\b/', $text, $directionMatch)) {
            return ['type' => trim($text) === '' ? 'NOISE' : (preg_match('/\b(?:NFP|CPI|FOMC|NEWS)\b/', $text) ? 'NEWS_COMMENT' : 'GENERAL_MESSAGE'), 'actions' => []];
        }
        preg_match_all('/\b(BUY|SELL)\b/', $text, $directions);
        if (count(array_unique($directions[1])) !== 1) {
            return $this->review('Qarama-qarshi BUY/SELL yo‘nalishlari.');
        }
        $symbols = [];
        foreach (config('trading.symbols') as $symbol => $spec) {
            foreach (array_merge([$symbol], $spec['aliases']) as $alias) {
                if (preg_match('/(?<![A-Z])'.preg_quote($alias, '/').'(?![A-Z])/', $text)) {
                    $symbols[$symbol] = true;
                }
            }
        }
        if (count($symbols) !== 1) {
            return $this->review('Bitta qo‘llab-quvvatlanadigan instrument kerak.');
        }
        $number = '(\d+(?:\.\d+)?)';
        $slPattern = '/\b(?:SL|STOP\s*LOSS)\s*[:=@-]?\s*'.$number.'/';
        $tpPattern = '/\b(?:TP(?:[1-9]|\s+[1-9](?=\s*[:=]))?|TAKE\s*PROFIT(?:[1-9])?)\s*[:=@-]?\s*'.$number.'/';
        preg_match_all($slPattern, $text, $stops);
        preg_match_all($tpPattern, $text, $targets);
        if (count($stops[1]) !== 1 || empty($targets[1])) {
            return $this->review('Entry, bitta SL va kamida bitta TP aniq ko‘rsatilishi kerak.');
        }
        $entryText = preg_replace([$slPattern, $tpPattern], '', $text);
        $entryText = preg_replace('/\b(?:TP|SL)\s*\d*/', '', $entryText);
        // Any other numbers (leverage, lot size, timestamps, etc.) require human review.
        preg_match_all('/(?<![A-Z\d])\d+(?:\.\d+)?(?![A-Z\d])/', $entryText, $entries);
        if (count($entries[0]) < 1 || count($entries[0]) > 2 || str_contains($entryText, '%')) {
            return $this->review('Entry narxi noaniq yoki qo‘shimcha sonlar bor.');
        }
        if (count($entries[0]) === 2 && ! preg_match('/\d+(?:\.\d+)?\s*-\s*\d+(?:\.\d+)?/', $entryText)) {
            return $this->review('Ikkita narx entry diapazoni sifatida yozilmagan.');
        }
        $entryMin = min(array_map('floatval', $entries[0]));
        $entryMax = max(array_map('floatval', $entries[0]));
        $stop = (float) $stops[1][0];
        $tps = array_values(array_unique(array_map('floatval', $targets[1])));
        $direction = $directionMatch[1];
        sort($tps);
        if ($direction === 'SELL') {
            rsort($tps);
        }
        if ($entryMin <= 0 || $stop <= 0 || min($tps) <= 0 || ($direction === 'BUY' ? ($stop >= $entryMin || min($tps) <= $entryMax) : ($stop <= $entryMax || max($tps) >= $entryMin))) {
            return $this->review('SL/TP narxlari yo‘nalishga mos kelmaydi.');
        }
        if (preg_match('/\b(?:BUY|SELL)\s+STOP\b/', $text)) {
            return $this->review('Stop-entry buyurtmalari hali qo‘llab-quvvatlanmaydi.');
        }

        return [
            'type' => 'NEW_SIGNAL', 'actions' => [], 'confidence' => 0.95,
            'signal' => ['symbol' => array_key_first($symbols), 'direction' => $direction, 'entry_type' => $entryMin === $entryMax ? 'PRICE' : 'RANGE', 'entry_min' => $entryMin, 'entry_max' => $entryMax, 'stop_loss' => $stop, 'take_profits' => $tps],
        ];
    }

    private function review(string $reason): array
    {
        return ['type' => 'REVIEW_REQUIRED', 'actions' => [], 'confidence' => 0, 'reason' => $reason];
    }
}
