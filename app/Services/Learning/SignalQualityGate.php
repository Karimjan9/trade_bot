<?php

namespace App\Services\Learning;

use App\Models\ChannelMlModel;
use App\Models\MlObservation;
use App\Models\MlPrediction;
use App\Models\TelegramChannel;

class SignalQualityGate
{
    public function evaluate(?MlObservation $observation): array
    {
        if (! $observation) {
            return ['action' => 'ABSTAIN', 'reason' => 'NO_CAUSAL_OBSERVATION'];
        }
        $channel = TelegramChannel::findOrFail($observation->channel_id);
        if ($channel->source !== 'manual' && ! $channel->learning_eligible) {
            return ['action' => 'ABSTAIN', 'reason' => 'LEARNING_DISABLED'];
        }
        $query = ChannelMlModel::where('channel_id', $observation->channel_id)->where('feature_version', $observation->feature_version)
            ->where('data_until', '<', $observation->observed_at)->where('created_at', '<=', $observation->observed_at)
            ->where('expires_at', '>=', $observation->observed_at)->whereNotNull('artifact');
        $model = (clone $query)->where('state', 'ACTIVE_PAPER')->where('activated_at', '<=', $observation->observed_at)->latest('id')->first()
            ?? $query->where('state', 'SHADOW_ONLY')->latest('id')->first();
        if (! $model) {
            return ['action' => 'ABSTAIN', 'reason' => 'NO_VALIDATED_MODEL', 'observation_id' => $observation->id];
        }
        $result = app(PortablePredictor::class)->predict($model->artifact, $observation->features, PortablePredictor::scope($observation->plan));
        $would = $result['action'];
        $reason = $result['supported'] ? 'MODEL_PREDICTION' : 'OUTSIDE_MODEL_SUPPORT';
        if ($model->state !== 'ACTIVE_PAPER' || ! ($observation->context['history_complete_enough'] ?? false) || $observation->origin !== 'realtime') {
            $result['action'] = 'ABSTAIN';
            $reason = $model->state !== 'ACTIVE_PAPER' ? 'SHADOW_MODEL' : 'INSUFFICIENT_CAUSAL_CONTEXT';
        }
        $prediction = MlPrediction::firstOrCreate(['observation_id' => $observation->id, 'model_id' => $model->id],
            ['probability' => $result['probability'], 'expected_r' => $result['expected_r'], 'action' => $result['action'],
                'explanation' => $result['explanation'] + ['would_action' => $would, 'reason' => $reason]]);

        return ['model_id' => $model->id, 'observation_id' => $observation->id, 'action' => $prediction->action,
            'probability' => $prediction->probability, 'expected_r' => $prediction->expected_r, 'reason' => $prediction->explanation['reason'], 'explanation' => $prediction->explanation];
    }
}
