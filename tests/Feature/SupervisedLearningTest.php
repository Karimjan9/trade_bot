<?php

namespace Tests\Feature;

use App\Jobs\LearnChannelModel;
use App\Models\ChannelMlModel;
use App\Models\ChannelPolicy;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\MlPrediction;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Learning\ChannelModelTrainer;
use App\Services\Learning\MarketFeatures;
use App\Services\Learning\ModelDriftMonitor;
use App\Services\Learning\ObservationBackfill;
use App\Services\Learning\ObservationRecorder;
use App\Services\Learning\OutcomeLabeler;
use App\Services\Learning\PortablePredictor;
use App\Services\Learning\SignalQualityGate;
use App\Services\Signals\ChannelRegistry;
use App\Services\Trading\DecisionEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupervisedLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        config(['trading.arbitration.collection_seconds' => 0]);
    }

    private function channel(string $source = 'manual'): TelegramChannel
    {
        return app(ChannelRegistry::class)->add(['username' => 'ml_channel', 'title' => 'ML research', 'source' => $source, 'research_settings' => ['XAUUSD' => ['pip_size' => .1, 'horizon_seconds' => 60]]]);
    }

    private function quote($at = null, float $bid = 3300, float $ask = 3300.2): MarketSnapshot
    {
        return MarketSnapshot::create(['symbol' => 'XAUUSD', 'recorded_at' => $at ?? now(), 'bid' => $bid, 'ask' => $ask, 'market_open' => true, 'source' => 'test', 'is_historical' => false]);
    }

    private function signal(TelegramChannel $channel, $at = null, array $overrides = []): Signal
    {
        $message = TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => 1 + (int) $channel->messages()->max('telegram_message_id'), 'raw_text' => 'GOLD BUY 3299-3301 SL 3295 TP 3310', 'latest_text' => '', 'raw_json' => [], 'posted_at' => $at ?? now(), 'is_historical' => false]);

        return Signal::create(array_replace(['channel_id' => $channel->id, 'message_id' => $message->id, 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'entry_type' => 'RANGE', 'entry_min' => 3299, 'entry_max' => 3301, 'stop_loss' => 3295, 'take_profits' => [3310], 'confidence' => .99, 'status' => 'ready', 'signal_time' => $at ?? now(), 'fingerprint' => (string) Str::uuid()], $overrides));
    }

    private function history($at): void
    {
        for ($i = 60; $i > 0; $i--) {
            $this->quote($at->copy()->subMinutes($i), 3300 - $i * .1, 3300.2 - $i * .1);
        }
    }

    private function model(TelegramChannel $channel, array $overrides = []): ChannelMlModel
    {
        return ChannelMlModel::create(array_replace(['channel_id' => $channel->id, 'fingerprint' => (string) Str::uuid(), 'feature_version' => MarketFeatures::VERSION, 'state' => 'ACTIVE_PAPER',
            'artifact' => ['feature_version' => MarketFeatures::VERSION, 'features' => ['direction_buy'], 'impute' => [0], 'mean' => [0], 'scale' => [1], 'classifier' => ['weights' => [0], 'intercept' => -2], 'calibrator' => ['weight' => 1, 'intercept' => 0], 'regressor' => ['weights' => [0], 'intercept' => -.7], 'threshold' => .55, 'max_abs_z' => 8, 'scopes' => ['XAUUSD:BUY:fixed_tp']],
            'report' => [], 'data_until' => now()->subHour(), 'activated_at' => now()->subMinute(), 'created_at' => now()->subMinute(), 'expires_at' => now()->addDay()], $overrides));
    }

    public function test_features_ignore_unfinished_future_candles_and_later_provider_edits(): void
    {
        $channel = $this->channel();
        $this->history(now());
        $signal = $this->signal($channel);
        $quote = $this->quote();
        $before = app(MarketFeatures::class)->build($signal, $quote, now());
        $this->quote(now()->addSecond(), 5000, 5001);
        $this->quote(now()->subSecond(), 4000, 4001); // Previous closed minute actually changes features.
        $changed = app(MarketFeatures::class)->build($signal, $quote, now());
        $this->assertNotEquals($before['features'], $changed['features']);
        $this->quote(now(), 9000, 9001); // Current minute has not closed.
        $signal->message->update(['latest_text' => 'TP hit!', 'deleted_at' => now()]);
        $this->assertSame($changed, app(MarketFeatures::class)->build($signal, $quote, now()));
        $this->assertTrue($before['context']['history_complete_enough']);
        $this->assertGreaterThan(0, $before['features']['alignment_15']);
    }

    public function test_rejected_signal_is_still_observed_and_labeled_after_costs(): void
    {
        $channel = $this->channel();
        $this->history(now());
        $this->quote();
        $this->model($channel);
        $signal = $this->signal($channel);
        $decision = app(DecisionEngine::class)->evaluate($signal);
        $this->assertContains('ML_LOW_EXPECTED_QUALITY', $decision->reasons);
        $this->assertSame('SKIP', $decision->context['ml']['action']);
        $this->assertDatabaseCount('paper_trades', 0);
        $observation = MlObservation::firstOrFail();
        $features = $observation->features;
        $this->quote(now()->addSeconds(20), 3310, 3310.2);
        $this->travel(30)->seconds();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('COMPLETE', $observation->fresh()->state);
        $this->assertGreaterThan(0, $observation->fresh()->outcome['net_r']);
        $this->assertLessThan((3310 - 3300.25) / (3300.25 - 3295), $observation->fresh()->outcome['net_r']);
        $this->assertSame($features, $observation->fresh()->features);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_unknown_market_context_abstains_and_cannot_bypass_hard_risk(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->model($channel);
        RiskProfile::current()->update(['emergency_stop' => true]);
        $decision = app(DecisionEngine::class)->evaluate($this->signal($channel));
        $this->assertSame('ABSTAIN', $decision->context['ml']['action']);
        $this->assertContains('EMERGENCY_STOP', $decision->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertDatabaseCount('ml_observations', 1);
    }

    public function test_outcome_uses_stop_gaps_and_does_not_label_an_unseen_future_win(): void
    {
        $channel = $this->channel();
        $at = now();
        $quote = $this->quote();
        $signal = $this->signal($channel);
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at);
        $this->quote($at->copy()->addSeconds(20), 3293, 3293.2);
        $this->quote($at->copy()->addSeconds(40), 3320, 3320.2);
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('PENDING', $observation->fresh()->state);
        $this->travel(25)->seconds();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('COMPLETE', $observation->fresh()->state);
        $this->assertLessThan(-1, $observation->fresh()->outcome['net_r']);
        $this->assertSame('STOP', $observation->fresh()->outcome['reason']);
    }

    public function test_sparse_ticks_and_unfilled_entries_do_not_become_training_losses(): void
    {
        $channel = $this->channel();
        $at = now();
        $signal = $this->signal($channel);
        $quote = $this->quote();
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at);
        $this->quote($at->copy()->addSeconds(45), 3310, 3310.2);
        $this->travel(50)->seconds();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('DATA_GAP', $observation->fresh()->state);
        $this->assertSame('INSUFFICIENT_DATA', app(ChannelModelTrainer::class)->fit($channel)->state);
        $this->assertSame(0, ChannelMlModel::first()->report['rows']);
    }

    public function test_profit_lock_replay_locks_50_pips_after_100_and_then_exits(): void
    {
        $channel = $this->channel();
        $at = now();
        $quote = $this->quote();
        $signal = $this->signal($channel);
        $management = ['trailing_target' => true, 'pip_size' => .1, 'lock_step_pips' => 50, 'horizon_seconds' => 60];
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at, $management);
        $this->quote($at->copy()->addSeconds(20), 3310.3, 3310.5);
        $this->quote($at->copy()->addSeconds(40), 3305, 3305.2);
        $this->travel(45)->seconds();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('COMPLETE', $observation->fresh()->state);
        $this->assertGreaterThan(0, $observation->fresh()->outcome['net_r']);
        $this->assertSame('STOP', $observation->fresh()->outcome['reason']);
        $this->assertEqualsWithDelta(3304.95, $observation->fresh()->outcome['exit'], 1e-7);
    }

    public function test_backfill_never_uses_a_policy_trained_after_signal_time(): void
    {
        $channel = $this->channel();
        $at = now()->subHour();
        $this->quote($at);
        $this->signal($channel, $at, ['stop_loss' => null, 'take_profits' => []]);
        ChannelPolicy::create(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'status' => 'PAPER_ELIGIBLE', 'pip_size' => .1, 'sl_pips' => 30, 'target_pips' => 100, 'lock_step_pips' => 50, 'horizon_seconds' => 60, 'fingerprint' => 'future-policy', 'evidence' => [], 'evidence_end_at' => $at->copy()->subDay(), 'expires_at' => now()->addDay()]);
        $this->assertSame(0, app(ObservationBackfill::class)->run($channel));
        $this->assertDatabaseCount('ml_observations', 0);
        $this->signal($channel, $at); // Complete original levels can be replayed.
        $this->assertSame(1, app(ObservationBackfill::class)->run($channel));
    }

    public function test_future_expired_and_out_of_distribution_models_cannot_reject(): void
    {
        $channel = $this->channel();
        $this->history(now());
        $quote = $this->quote();
        $signal = $this->signal($channel);
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, now());
        $this->model($channel, ['data_until' => now()->addMinute()]);
        $this->assertSame('ABSTAIN', app(SignalQualityGate::class)->evaluate($observation)['action']);
        $model = $this->model($channel, ['expires_at' => now()->subSecond()]);
        $this->assertSame('ABSTAIN', app(SignalQualityGate::class)->evaluate($observation)['action']);
        $artifact = $model->artifact;
        $artifact['scopes'] = ['EURUSD:SELL:fixed_tp'];
        $this->model($channel, ['artifact' => $artifact]);
        $this->assertSame('ABSTAIN', app(SignalQualityGate::class)->evaluate($observation)['action']);
    }

    public function test_no_learning_permission_means_no_training_and_audited_opt_in(): void
    {
        $channel = $this->channel('telegram');
        $trainer = app(ChannelModelTrainer::class);
        $model = $trainer->fit($channel);
        $this->assertSame('LEARNING_DISABLED', $model->state);
        $this->assertSame($model->id, $trainer->fit($channel)->id);
        Queue::fake();
        $this->actingAs(User::factory()->create())->post('/channels/'.$channel->id.'/learn', ['learning_eligible' => 1])->assertRedirect();
        $this->assertTrue($channel->fresh()->learning_eligible);
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.learning_eligible']);
        Queue::assertPushedOn('ml', LearnChannelModel::class);
        $this->assertSame('INSUFFICIENT_DATA', $trainer->fit($channel->fresh())->state);
    }

    public function test_later_outcomes_quarantine_a_deteriorating_model_including_missed_winners(): void
    {
        config(['trading.ml.drift_window' => 3, 'trading.ml.drift_min_selected' => 1]);
        $channel = $this->channel();
        $model = $this->model($channel);
        foreach ([1, -1, 1] as $i => $r) {
            $signal = $this->signal($channel);
            $quote = $this->quote();
            $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, now());
            $observation->update(['state' => 'COMPLETE', 'outcome' => ['net_r' => $r], 'label_end_at' => now()->addSecond()]);
            MlPrediction::create(['observation_id' => $observation->id, 'model_id' => $model->id, 'action' => $i === 0 ? 'ALLOW' : 'SKIP', 'probability' => .5, 'expected_r' => 0, 'explanation' => []]);
        }
        // Change the selected observation to a losing result. Rejected winners remain in baseline.
        MlObservation::oldest('id')->first()->update(['outcome' => ['net_r' => -1]]);
        $this->travel(2)->seconds();
        $this->assertTrue(app(ModelDriftMonitor::class)->check($model));
        $this->assertSame('QUARANTINED', $model->fresh()->state);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ml.drift_detected']);
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'ML_MODEL_DRIFT', 'channel_id' => $channel->id]);
        $this->assertFalse(app(ModelDriftMonitor::class)->check($model->fresh()));
    }

    public function test_portable_linear_inference_explains_direction_and_abstains_outside_support(): void
    {
        $model = $this->model($this->channel());
        $artifact = $model->artifact;
        $artifact['classifier']['weights'] = [2];
        $artifact['classifier']['intercept'] = 0;
        $artifact['regressor']['weights'] = [1.5];
        $artifact['regressor']['intercept'] = -.5;
        $result = app(PortablePredictor::class)->predict($artifact, ['direction_buy' => 1], 'XAUUSD:BUY:fixed_tp');
        $this->assertEqualsWithDelta(.8807970779, $result['probability'], 1e-9);
        $this->assertEquals(1, $result['expected_r']);
        $this->assertSame('ALLOW', $result['action']);
        $this->assertSame('ABSTAIN', app(PortablePredictor::class)->predict($artifact, ['direction_buy' => 20], 'XAUUSD:BUY:fixed_tp')['action']);
    }

    public function test_unfilled_entry_is_not_a_loss_and_has_no_label_for_training(): void
    {
        $channel = $this->channel();
        $at = now();
        $signal = $this->signal($channel);
        $quote = $this->quote(bid: 3400, ask: 3400.2);
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at);
        for ($i = 1; $i <= 9; $i++) {
            $this->quote($at->copy()->addSeconds($i * 20), 3400, 3400.2);
        }
        $this->travel(181)->seconds();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('NO_ENTRY', $observation->fresh()->state);
        $this->assertArrayNotHasKey('net_r', $observation->fresh()->outcome);
    }

    public function test_python_training_activation_inference_parity_and_versioned_relearning(): void
    {
        $channel = $this->channel();
        $quote = $this->quote();
        $start = now()->subDay();
        $insert = function (int $from, int $until) use ($channel, $quote, $start) {
            for ($i = $from; $i < $until; $i++) {
                $at = $start->copy()->addMinutes($i * 5);
                $signal = $this->signal($channel, $at);
                MlObservation::create(['signal_id' => $signal->id, 'channel_id' => $channel->id, 'snapshot_id' => $quote->id,
                    'feature_version' => MarketFeatures::VERSION, 'features' => ['alignment_15' => $i % 2 ? 1 : -1, 'spread_r' => .03, 'missing' => null],
                    'context' => ['history_complete_enough' => true, 'hypothesis' => $i % 2 ? 'TREND_ALIGNED' : 'COUNTER_TREND'],
                    'plan' => ['symbol' => 'XAUUSD', 'direction' => 'BUY', 'family' => 'fixed_tp'], 'origin' => 'historical_replay',
                    'observed_at' => $at, 'label_end_at' => $at->copy()->addMinute(), 'state' => 'COMPLETE', 'outcome' => ['net_r' => $i % 2 ? 1.8 : -1.05]]);
            }
        };
        $insert(0, 240);
        $trainer = app(ChannelModelTrainer::class);
        $model = $trainer->fit($channel);
        $this->assertSame('ACTIVE_PAPER', $model->state);
        $this->assertTrue($model->report['candidate_pass']);
        foreach (array_slice($model->report['test_predictions'], 0, 2) as $python) {
            $observation = MlObservation::findOrFail($python['id']);
            $php = app(PortablePredictor::class)->predict($model->artifact, $observation->features, PortablePredictor::scope($observation->plan));
            $this->assertEqualsWithDelta($python['p'], $php['probability'], 1e-10);
            $this->assertEqualsWithDelta($python['expected_r'], $php['expected_r'], 1e-10);
        }
        $this->assertSame($model->id, $trainer->fit($channel)->id);
        $insert(240, 280);
        $challenger = $trainer->fit($channel);
        $this->assertNotEquals($model->id, $challenger->id);
        $this->assertSame('SHADOW_ONLY', $challenger->state);
        $this->assertSame('AWAITING_NEW_INCUMBENT_HOLDOUT', $challenger->report['promotion_reason']);
        $this->assertSame('ACTIVE_PAPER', $model->fresh()->state);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertSame([], glob(storage_path('app/private/ml/*.input.json')));
        $this->assertSame([], glob(storage_path('app/private/ml/*.output.json')));
    }
}
