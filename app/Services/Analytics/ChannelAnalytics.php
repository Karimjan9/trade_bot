<?php

namespace App\Services\Analytics;

use App\Models\ChannelProfile;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\TelegramChannel;
use App\Models\TelegramMessageEvent;
use Illuminate\Support\Collection;

class ChannelAnalytics
{
    public function metrics(Collection $returns): array
    {
        $n = $returns->count();
        $positive = $returns->filter(fn ($v) => $v > 0)->sum();
        $negative = abs($returns->filter(fn ($v) => $v < 0)->sum());
        $cumulative = $peak = $maxDd = 0;
        foreach ($returns as $r) {
            $cumulative += $r;
            $peak = max($peak, $cumulative);
            $maxDd = max($maxDd, $peak - $cumulative);
        }

        return ['sample_size' => $n, 'win_rate' => $n ? round($returns->filter(fn ($v) => $v > 0)->count() / $n * 100, 2) : null, 'expectancy_r' => $n ? round($returns->avg(), 4) : null, 'profit_factor' => $negative > 0 ? round($positive / $negative, 3) : null, 'max_drawdown_r' => round($maxDd, 3), 'total_r' => round($cumulative, 3)];
    }

    public function refresh(TelegramChannel $channel): ChannelProfile
    {
        $trades = PaperTrade::where('channel_id', $channel->id)->where('mode', 'paper')->where('status', 'closed')->orderBy('closed_at')->orderBy('id')->get();
        $returns = $trades->map(fn ($t) => $t->risk_amount > 0 ? $t->realized_pnl / $t->risk_amount : 0);
        $metrics = $this->metrics($returns);
        $metrics['recent_30'] = $this->metrics($returns->take(-30));
        $events = TelegramMessageEvent::where('channel_id', $channel->id)->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');
        $newCount = max(1, (int) ($events['new'] ?? 0));
        $transparency = max(0, 100 - 60 * ($events['delete'] ?? 0) / $newCount - 20 * ($events['edit'] ?? 0) / $newCount);
        $trust = $returns->count() >= 20 ? round(max(0, min(100, 50 + $returns->avg() * 30)) * 0.65 + $transparency * 0.35, 1) : null;
        $drift = 'INSUFFICIENT_DATA';
        if ($returns->count() >= 60) {
            $recent = $returns->take(-30)->avg();
            $prior = $returns->slice(-60, 30)->avg();
            $drift = $recent < -0.25 && $recent < $prior - 0.3 ? 'SEVERE' : ($recent < $prior - 0.2 ? 'DETECTED' : 'STABLE');
        }
        $snapshots = MarketSnapshot::whereIn('id', $trades->pluck('snapshot_id'))->get()->keyBy('id');
        $dna = [];
        foreach (['direction', 'symbol', 'session', 'regime'] as $dimension) {
            $groups = $trades->groupBy(fn ($t) => in_array($dimension, ['session', 'regime']) ? ($snapshots[$t->snapshot_id]?->$dimension ?? 'UNKNOWN') : $t->$dimension);
            $dna[$dimension] = $groups->map(fn ($group) => $this->metrics($group->map(fn ($t) => $t->realized_pnl / $t->risk_amount)))->all();
        }
        $dna['transparency'] = ['score' => round($transparency, 1), 'edit_events' => (int) ($events['edit'] ?? 0), 'delete_events' => (int) ($events['delete'] ?? 0), 'note' => 'Event ratios only; edits/deletes alone do not prove dishonesty.'];
        $dna['source'] = 'closed_paper_trades';
        $profile = ChannelProfile::updateOrCreate(['channel_id' => $channel->id], ['metrics' => $metrics, 'dna' => $dna, 'trust_score' => $trust, 'drift' => $drift, 'computed_at' => now()]);
        if ($drift === 'SEVERE') {
            $channel->agents()->where('role', 'champion')->update(['state' => 'QUARANTINE']);
        }

        return $profile;
    }
}
