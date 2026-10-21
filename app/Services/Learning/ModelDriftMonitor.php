<?php

namespace App\Services\Learning;

use App\Models\AuditLog;
use App\Models\ChannelMlModel;
use App\Models\MlPrediction;
use App\Models\RiskProfile;
use App\Services\Monitoring\SignalIssues;
use Illuminate\Support\Facades\DB;

class ModelDriftMonitor
{
    public function check(ChannelMlModel $model): bool
    {
        if ($model->state !== 'ACTIVE_PAPER') {
            return false;
        }
        // Only predictions actually made before the outcome are admissible.
        $predictions = MlPrediction::where('model_id', $model->id)->join('ml_observations as o', 'o.id', '=', 'ml_predictions.observation_id')
            ->where('o.state', 'COMPLETE')->where('o.origin', 'realtime')->where('o.observed_at', '>', $model->data_until)->where('o.label_end_at', '<=', now())->whereColumn('ml_predictions.created_at', '<=', 'o.label_end_at')
            ->orderByDesc('o.observed_at')->limit(config('trading.ml.drift_window'))->select('ml_predictions.*', 'o.outcome')->get();
        if ($predictions->count() < config('trading.ml.drift_window')) {
            return false;
        }
        $baseline = $filtered = $selected = 0;
        foreach ($predictions as $prediction) {
            $outcome = json_decode($prediction->getRawOriginal('outcome'), true);
            $baseline += $outcome['net_r'];
            if ($prediction->action !== 'SKIP') {
                $filtered += $outcome['net_r'];
                $selected++;
            }
        }
        if (($selected < config('trading.ml.drift_min_selected') && ! ($baseline > 0 && $filtered < $baseline)) || ($filtered > 0 && $filtered >= $baseline)) {
            return false;
        }

        return DB::transaction(function () use ($model, $baseline, $filtered, $selected) {
            RiskProfile::current(true);
            $model->refresh();
            if ($model->state !== 'ACTIVE_PAPER') {
                return false;
            }
            $report = $model->report;
            $report['drift'] = ['detected_at' => now()->toIso8601String(), 'baseline_r' => $baseline, 'filtered_r' => $filtered, 'selected' => $selected];
            $model->update(['state' => 'QUARANTINED', 'report' => $report]);
            AuditLog::record('ml.drift_detected', 'channel:'.$model->channel_id, null, ['model_id' => $model->id] + $report['drift']);
            app(SignalIssues::class)->drift($model);

            return true;
        });
    }
}
