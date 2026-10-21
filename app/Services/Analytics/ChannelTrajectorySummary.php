<?php

namespace App\Services\Analytics;

use App\Models\MlObservation;
use App\Models\TelegramChannel;

class ChannelTrajectorySummary
{
    public function inspect(TelegramChannel $channel): array
    {
        $paths = MlObservation::where('channel_id', $channel->id)->where('path_state', 'COMPLETE')->where('path_end_at', '<=', now())->orderByDesc('observed_at')->limit(500)->get();

        return $paths->groupBy(fn ($o) => $o->plan['symbol'].':'.$o->plan['direction'].':'.$o->path_metrics['horizon_seconds'].':'.$o->path_metrics['pip_size'])
            ->map(function ($group) {
                $first = $group->first();
                $to100 = $group->filter(fn ($o) => isset($o->path_metrics['milestones'][100]));

                return ['symbol' => $first->plan['symbol'], 'direction' => $first->plan['direction'], 'horizon_seconds' => $first->path_metrics['horizon_seconds'], 'pip_size' => $first->path_metrics['pip_size'], 'count' => $group->count(),
                    'reached_50' => $group->filter(fn ($o) => isset($o->path_metrics['milestones'][50]))->count(), 'reached_100' => $to100->count(),
                    'median_adverse_before_100_pips' => $to100->map(fn ($o) => $o->path_metrics['milestones'][100]['adverse_before_pips'])->median(),
                    'median_seconds_to_100' => $to100->map(fn ($o) => $o->path_metrics['milestones'][100]['elapsed_seconds'])->median(),
                    'stop_before_100' => $to100->filter(fn ($o) => $o->path_metrics['milestones'][100]['initial_stop_already_hit'])->count()];
            })->values()->all();
    }
}
