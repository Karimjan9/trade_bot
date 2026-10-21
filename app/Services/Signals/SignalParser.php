<?php

namespace App\Services\Signals;

class SignalParser
{
    public const VERSION = 'rules-1.2';

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
        if (preg_match('/\b(?:LOCK|PROTECT)\s+([0-9]+(?:\.[0-9]+)?)\s*PIPS?\b/', $text, $locked)) {
            $actions[] = ['type' => 'PROFIT_LOCK', 'locked_pips' => (float) $locked[1]];
        } elseif (preg_match('/(?:\bBEZ\s+UBITKA\b|БЕЗ\s*УБЫТКА|БЕЗУБЫТОК|BEZ\s+UBYTKA)/u', $text)) {
            if (preg_match('/\bTP\s*([1-9])\b/', $text, $target)) {
                $actions[] = ['type' => 'PROFIT_LOCK', 'target_index' => (int) $target[1] - 1];
            } elseif (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*PIPS?\b/', $text, $lockPips)) {
                $actions[] = ['type' => 'PROFIT_LOCK', 'locked_pips' => (float) $lockPips[1]];
            } else {
                $actions[] = ['type' => 'PROFIT_LOCK', 'use_channel_policy' => true];
            }
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
        if (preg_match('/\b(?:SAVE|SECURE\s+(?:PROFIT|POSITION|TRADE))\s+(\d+(?:\.\d+)?)\s*%/', $text, $save)) {
            $fraction = (float) $save[1] / 100;
            if ($fraction <= 0 || $fraction >= 1) {
                return $this->review('Save ulushi 0% va 100% orasida bo‘lishi kerak.');
            }

            return ['type' => 'SIGNAL_UPDATE', 'intent' => 'SAVE_POSITION', 'actions' => [['type' => 'PARTIAL_CLOSE', 'fraction' => $fraction, 'requires_profit' => true], ['type' => 'BREAK_EVEN', 'requires_profit' => true]], 'confidence' => 0.95];
        }
        if (preg_match('/^\s*(?:SAVE(?:\s+NOW)?|SECURE\s+(?:PROFIT|POSITION|TRADE))[.!]?\s*$/', $text)) {
            return $this->review('Save uchun yopiladigan ulush yoki tasdiqlangan kanal qoidasi kerak.') + ['intent' => 'SAVE_POSITION', 'missing_fields' => ['partial_close_fraction']];
        }
        preg_match_all('/(?<![\d.])([+-]?\d+(?:\.\d+)?)\s*(PIPS?|POINTS?)\b/', $text, $reported, PREG_SET_ORDER);
        $explicitReport = preg_match('/\b(?:TP\d*\s+HIT|PROFIT\s+BOOKED|PIPS\s+PROFIT|STOP\s+HIT|SL\s+HIT)\b/', $text);
        $bareClaim = $reported && ! preg_match('/\b(?:BUY|SELL|ENTRY|SL|TP\d*|TARGET|RISK|STOP)\b/', $text);
        if ($explicitReport || $bareClaim) {
            return ['type' => 'RESULT_REPORT', 'actions' => [], 'verification' => 'UNVERIFIED', 'claims' => array_map(fn ($claim) => ['value' => (float) $claim[1], 'unit' => str_starts_with($claim[2], 'PIP') ? 'PIPS' : 'POINTS', 'verification' => 'UNVERIFIED'], $reported)];
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
        if (count($stops[1]) > 1) {
            return $this->review('Bitta aniq SL ko‘rsatilishi kerak.');
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
        $stop = isset($stops[1][0]) ? (float) $stops[1][0] : null;
        $tps = array_values(array_unique(array_map('floatval', $targets[1])));
        $direction = $directionMatch[1];
        sort($tps);
        if ($direction === 'SELL') {
            rsort($tps);
        }
        if ($entryMin <= 0 || ($stop !== null && ($stop <= 0 || ($direction === 'BUY' ? $stop >= $entryMin : $stop <= $entryMax))) || ($tps && (min($tps) <= 0 || ($direction === 'BUY' ? min($tps) <= $entryMax : max($tps) >= $entryMin)))) {
            return $this->review('SL/TP narxlari yo‘nalishga mos kelmaydi.');
        }
        if (preg_match('/\b(?:BUY|SELL)\s+STOP\b/', $text)) {
            return $this->review('Stop-entry buyurtmalari hali qo‘llab-quvvatlanmaydi.');
        }

        $candidate = ['symbol' => array_key_first($symbols), 'direction' => $direction, 'entry_type' => $entryMin === $entryMax ? 'PRICE' : 'RANGE', 'entry_min' => $entryMin, 'entry_max' => $entryMax, 'stop_loss' => $stop, 'take_profits' => $tps];
        $missing = [];
        if ($stop === null) {
            $missing[] = 'stop_loss';
        }
        if (! $tps) {
            $missing[] = 'take_profits';
        }
        if ($missing) {
            return ['type' => 'PARTIAL_SIGNAL', 'actions' => [], 'confidence' => 0.95, 'reason' => 'Yetishmagan SL/TP kanal tarixidan o‘rganilgan agent siyosati bilan to‘ldiriladi.', 'reason_code' => 'INCOMPLETE_SIGNAL', 'candidate' => $candidate, 'missing_fields' => $missing, 'field_source' => 'provider_text'];
        }

        return [
            'type' => 'NEW_SIGNAL', 'actions' => [], 'confidence' => 0.95,
            'signal' => $candidate, 'field_source' => 'provider_text',
        ];
    }

    private function review(string $reason): array
    {
        return ['type' => 'REVIEW_REQUIRED', 'actions' => [], 'confidence' => 0, 'reason' => $reason];
    }
}
