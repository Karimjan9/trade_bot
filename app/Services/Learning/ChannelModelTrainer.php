<?php

namespace App\Services\Learning;

use App\Models\AuditLog;
use App\Models\ChannelMlModel;
use App\Models\MlObservation;
use App\Models\RiskProfile;
use App\Models\TelegramChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class ChannelModelTrainer
{
    public function fit(TelegramChannel $channel): ChannelMlModel
    {
        $eligible = $channel->source === 'manual' || $channel->learning_eligible;
        $observations = MlObservation::where('channel_id', $channel->id)->where('state', 'COMPLETE')->where('feature_version', MarketFeatures::VERSION)
            ->where('label_end_at', '<=', now())->orderByDesc('observed_at')->orderByDesc('id')->limit(config('trading.ml.max_rows'))->get()->reverse();
        $rows = [];
        foreach ($observations as $observation) {
            if (! ($observation->context['history_complete_enough'] ?? false)) {
                continue;
            }
            $rows[] = ['id' => $observation->id, 'features' => $observation->features, 'observed_at' => $observation->observed_at->toIso8601String(),
                'label_end_at' => $observation->label_end_at->toIso8601String(), 'net_r' => $observation->outcome['net_r'],
                'scope' => PortablePredictor::scope($observation->plan), 'hypothesis' => $observation->context['hypothesis'] ?? 'UNDETERMINED'];
        }
        $payload = ['feature_version' => MarketFeatures::VERSION, 'rows' => $eligible ? $rows : [], 'config' => config('trading.ml')];
        unset($payload['config']['python']);
        $fingerprint = hash('sha256', json_encode([$channel->id, $eligible, $payload, hash_file('sha256', base_path('services/ml/train.py')), hash_file('sha256', base_path('services/ml/linear.py'))], JSON_THROW_ON_ERROR));
        if ($existing = ChannelMlModel::where('fingerprint', $fingerprint)->first()) {
            return $existing;
        }
        $dataUntil = $observations->max('label_end_at');
        if (! $eligible) {
            $result = ['state' => 'LEARNING_DISABLED', 'artifact' => null, 'report' => ['rows' => 0, 'reason' => 'Channel learning_eligible is disabled']];
        } elseif (count($rows) < config('trading.ml.min_train') + config('trading.ml.min_calibration') + config('trading.ml.min_test')) {
            $result = ['state' => 'INSUFFICIENT_DATA', 'artifact' => null, 'report' => ['rows' => count($rows), 'required_at_least' => config('trading.ml.min_train') + config('trading.ml.min_calibration') + config('trading.ml.min_test')]];
        } else {
            $directory = storage_path('app/private/ml');
            File::ensureDirectoryExists($directory);
            $input = $directory.'/'.$fingerprint.'.input.json';
            $output = $directory.'/'.$fingerprint.'.output.json';
            File::put($input, json_encode($payload, JSON_THROW_ON_ERROR));
            try {
                $process = new Process([config('trading.ml.python'), base_path('services/ml/train.py'), '--input', $input, '--output', $output], base_path(), ['OMP_NUM_THREADS' => '1', 'OPENBLAS_NUM_THREADS' => '1']);
                $process->setTimeout(120)->mustRun();
                $result = json_decode(File::get($output), true, flags: JSON_THROW_ON_ERROR);
            } finally {
                File::delete($input, $output);
            }
        }

        return DB::transaction(function () use ($channel, $fingerprint, $dataUntil, $result, $rows) {
            RiskProfile::current(true);
            $channel->refresh();
            if ($channel->source !== 'manual' && ! $channel->learning_eligible) {
                // A permission switch while Python was running takes effect before activation.
                $result = ['state' => 'LEARNING_DISABLED', 'artifact' => null, 'report' => ['rows' => 0, 'reason' => 'Learning disabled during training']];
            }
            if ($existing = ChannelMlModel::where('fingerprint', $fingerprint)->first()) {
                return $existing;
            }
            $state = $result['state'];
            $report = $result['report'];
            $incumbent = ChannelMlModel::where('channel_id', $channel->id)->where('state', 'ACTIVE_PAPER')->where('expires_at', '>=', now())->latest('id')->first();
            if ($state === 'CANDIDATE') {
                $state = 'ACTIVE_PAPER';
                if (! $dataUntil || $dataUntil->copy()->addDays(config('trading.ml.lifetime_days'))->lt(now())) {
                    $state = 'SHADOW_ONLY';
                    $report['promotion_reason'] = 'STALE_EVIDENCE';
                } elseif ($incumbent) {
                    $start = CarbonImmutable::parse($report['split']['test_start']);
                    if ($incumbent->data_until->gte($start)) {
                        $state = 'SHADOW_ONLY';
                        $report['promotion_reason'] = 'AWAITING_NEW_INCUMBENT_HOLDOUT';
                    } else {
                        $test = array_values(array_filter($rows, fn ($r) => in_array($r['id'], $report['split']['test_ids'])));
                        $total = $peak = $drawdown = 0;
                        foreach ($test as $row) {
                            $prediction = app(PortablePredictor::class)->predict($incumbent->artifact, $row['features'], $row['scope']);
                            $total += $prediction['action'] === 'SKIP' ? 0 : $row['net_r'];
                            $peak = max($peak, $total);
                            $drawdown = max($drawdown, $peak - $total);
                        }
                        $report['incumbent_comparison'] = ['model_id' => $incumbent->id, 'total_r' => $total, 'max_drawdown_r' => $drawdown, 'same_test_rows' => count($test)];
                        if ($report['test_filtered']['total_r'] <= $total + count($test) * config('trading.ml.min_improvement_r_per_opportunity') || $report['test_filtered']['max_drawdown_r'] > $drawdown) {
                            $state = 'SHADOW_ONLY';
                            $report['promotion_reason'] = 'INCUMBENT_RETAINED';
                        }
                    }
                } elseif ($quarantined = ChannelMlModel::where('channel_id', $channel->id)->where('state', 'QUARANTINED')->latest('id')->first()) {
                    $testStart = CarbonImmutable::parse($report['split']['test_start']);
                    $quarantinedAt = CarbonImmutable::parse($quarantined->report['drift']['detected_at'] ?? $quarantined->updated_at);
                    if ($testStart->lte($quarantinedAt)) {
                        $state = 'SHADOW_ONLY';
                        $report['promotion_reason'] = 'AWAITING_POST_DRIFT_HOLDOUT';
                    }
                }
            }
            if ($state === 'ACTIVE_PAPER') {
                ChannelMlModel::where('channel_id', $channel->id)->where('state', 'ACTIVE_PAPER')->update(['state' => 'RETIRED']);
            }
            $model = ChannelMlModel::create(['channel_id' => $channel->id, 'fingerprint' => $fingerprint, 'feature_version' => MarketFeatures::VERSION,
                'state' => $state, 'artifact' => $result['artifact'], 'report' => $report, 'data_until' => $dataUntil,
                'activated_at' => $state === 'ACTIVE_PAPER' ? now() : null, 'expires_at' => $dataUntil?->copy()->addDays(config('trading.ml.lifetime_days'))]);
            AuditLog::record('ml.trained', 'channel:'.$channel->id, null, ['model_id' => $model->id, 'state' => $state, 'rows' => count($rows)]);

            return $model;
        });
    }
}
