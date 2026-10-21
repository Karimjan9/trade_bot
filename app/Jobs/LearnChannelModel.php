<?php

namespace App\Jobs;

use App\Models\ChannelMlModel;
use App\Models\EntryExperiment;
use App\Models\MlObservation;
use App\Models\TelegramChannel;
use App\Services\Analytics\ChannelStrategyLearner;
use App\Services\Analytics\ChannelUnits;
use App\Services\Analytics\EntryResearch;
use App\Services\Learning\ChannelModelTrainer;
use App\Services\Learning\ModelDriftMonitor;
use App\Services\Learning\ObservationBackfill;
use App\Services\Learning\OutcomeLabeler;
use App\Services\Learning\SignalTrajectory;
use App\Services\Trading\EntryPlans;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class LearnChannelModel implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(public int $channelId)
    {
        $this->onQueue('ml');
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    public function handle(): void
    {
        $channel = TelegramChannel::findOrFail($this->channelId);
        app(ObservationBackfill::class)->run($channel);
        foreach (MlObservation::where('channel_id', $channel->id)->whereIn('state', ['PENDING', 'DATA_GAP'])->orderByDesc('observed_at')->limit(config('trading.ml.max_rows'))->get() as $observation) {
            app(OutcomeLabeler::class)->label($observation);
        }
        foreach (MlObservation::where('channel_id', $channel->id)->whereIn('path_state', ['PENDING', 'TRACKING', 'DATA_GAP'])->orderBy('updated_at')->orderBy('id')->limit(config('trading.trajectory.batch_size'))->get() as $observation) {
            app(SignalTrajectory::class)->update($observation);
        }
        foreach (ChannelMlModel::where('channel_id', $channel->id)->where('state', 'ACTIVE_PAPER')->get() as $model) {
            app(ModelDriftMonitor::class)->check($model);
        }
        if (config('trading.entries.enabled') && ($channel->source === 'manual' || $channel->learning_eligible)) {
            foreach (MlObservation::where('channel_id', $channel->id)->whereNotIn('id', EntryExperiment::select('observation_id'))->orderByDesc('observed_at')->limit(100)->get() as $observation) {
                if ($pip = $observation->plan['pip_size'] ?? null) {
                    app(EntryResearch::class)->study($observation, app(EntryPlans::class)->variants($observation->plan['research_horizon_seconds'] ?? app(ChannelUnits::class)->horizon($channel, $observation->plan['symbol']), $pip));
                }
            }
        }
        app(ChannelModelTrainer::class)->fit($channel);
        $scopes = MlObservation::where('channel_id', $channel->id)->get(['plan'])->map(fn ($o) => [$o->plan['symbol'] ?? null, $o->plan['direction'] ?? null])->unique(fn ($scope) => implode(':', $scope));
        foreach ($scopes as [$symbol, $direction]) {
            if ($symbol && $direction) {
                app(ChannelStrategyLearner::class)->fit($channel, $symbol, $direction);
            }
        }
    }
}
