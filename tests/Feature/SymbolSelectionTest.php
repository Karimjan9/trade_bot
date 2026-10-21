<?php

namespace Tests\Feature;

use App\Jobs\ReconsiderSymbol;
use App\Models\ChannelMlModel;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Learning\MarketFeatures;
use App\Services\Learning\ObservationRecorder;
use App\Services\Learning\OutcomeLabeler;
use App\Services\Learning\SignalTrajectory;
use App\Services\Signals\ChannelRegistry;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\MarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SymbolSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfMinute());
        Queue::fake();
        for ($i = 20; $i > 0; $i--) {
            $this->quote(now()->subMinutes($i), 3300 - $i * .1);
        }
        $this->quote(now(), 3300);
    }

    private function quote($at, float $price): MarketSnapshot
    {
        return MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => $price, 'ask' => $price, 'source' => 'test', 'market_open' => true, 'is_historical' => false, 'recorded_at' => $at]);
    }

    private function signal(string $direction, ?float $expected = null, array $overrides = []): Signal
    {
        $n = TelegramChannel::count() + 1;
        $channel = app(ChannelRegistry::class)->add(['username' => 'channel_'.$n, 'title' => 'Channel '.$n, 'source' => 'manual']);
        $message = TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => 1, 'raw_text' => 'GOLD '.$direction, 'latest_text' => '', 'raw_json' => [], 'posted_at' => now(), 'is_historical' => false]);
        if ($expected !== null) {
            ChannelMlModel::create(['channel_id' => $channel->id, 'fingerprint' => (string) Str::uuid(), 'feature_version' => MarketFeatures::VERSION, 'state' => 'ACTIVE_PAPER', 'data_until' => now()->subDay(), 'activated_at' => now()->subHour(), 'created_at' => now()->subHour(), 'expires_at' => now()->addDay(), 'report' => [],
                'artifact' => ['features' => ['direction_buy'], 'impute' => [0], 'mean' => [0], 'scale' => [1], 'classifier' => ['weights' => [0], 'intercept' => log(4)], 'calibrator' => ['weight' => 1, 'intercept' => 0], 'regressor' => ['weights' => [0], 'intercept' => $expected], 'threshold' => .55, 'max_abs_z' => 8, 'scopes' => ['XAUUSD:'.$direction.':fixed_tp']]]);
        }

        return Signal::create(array_replace(['channel_id' => $channel->id, 'message_id' => $message->id, 'symbol' => 'XAUUSD', 'direction' => $direction, 'entry_type' => 'RANGE', 'entry_min' => 3299, 'entry_max' => 3301, 'stop_loss' => $direction === 'BUY' ? 3290 : 3310, 'take_profits' => [$direction === 'BUY' ? 3320 : 3280], 'confidence' => .99, 'status' => 'ready', 'signal_time' => now(), 'fingerprint' => (string) Str::uuid()], $overrides));
    }

    public function test_collection_window_selects_later_better_buy_and_keeps_both_observations(): void
    {
        config(['queue.default' => 'database']);
        $sell = $this->signal('SELL', .6);
        $this->assertSame('WAIT', app(DecisionEngine::class)->evaluate($sell)->action);
        Queue::assertPushed(ReconsiderSymbol::class);
        $this->travel(1)->seconds();
        $buy = $this->signal('BUY', 1.6);
        $this->assertSame('WAIT', app(DecisionEngine::class)->evaluate($buy)->action);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->travel(3)->seconds();
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertSame('PAPER_OPENED', $decisions[$buy->id]->action);
        $this->assertSame($buy->id, PaperTrade::first()->signal_id);
        $this->assertContains('OTHER_SYMBOL_CANDIDATE_SELECTED', $decisions[$sell->id]->reasons);
        $this->assertSame($buy->id, $decisions[$sell->id]->context['selection']['selected_signal_id']);
        $this->assertDatabaseCount('ml_observations', 2);
        $this->actingAs(User::factory()->create())->get('/signals/'.$buy->id)->assertOk()->assertSee('Bir instrument uchun umumiy tanlov');
    }

    public function test_opposing_signals_without_validated_models_wait_instead_of_first_arrival_winning(): void
    {
        $sell = $this->signal('SELL');
        $buy = $this->signal('BUY');
        $this->travel(4)->seconds();
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        foreach ([$sell, $buy] as $signal) {
            $this->assertSame('WAIT', $decisions[$signal->id]->action);
            $this->assertContains('OPPOSING_SIGNALS_NEED_MODEL_EVIDENCE', $decisions[$signal->id]->reasons);
        }
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertDatabaseCount('ml_observations', 2);
    }

    public function test_small_direction_edge_and_negative_utility_choose_wait(): void
    {
        $this->signal('SELL', 1.0);
        $this->signal('BUY', 1.05);
        $this->travel(4)->seconds();
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertContains('DIRECTION_EDGE_TOO_SMALL', collect($decisions)->first()->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
        // A positive raw expectation can still be unattractive after adverse/time allowance.
        config(['trading.arbitration.adverse_penalty' => 2]);
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertContains('NO_POSITIVE_DIRECTION_UTILITY', collect($decisions)->first()->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_many_sell_channels_do_not_outvote_a_better_buy_or_add_positions(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->signal('SELL', .7);
        }
        $buy = $this->signal('BUY', 1.5);
        $this->travel(4)->seconds();
        app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertDatabaseCount('paper_trades', 1);
        $this->assertSame($buy->id, PaperTrade::first()->signal_id);
        app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertDatabaseCount('paper_trades', 1);
    }

    public function test_open_position_is_held_when_stronger_opposite_signal_arrives(): void
    {
        $sell = $this->signal('SELL', .7);
        $this->travel(4)->seconds();
        app(DecisionEngine::class)->evaluate($sell);
        $trade = PaperTrade::firstOrFail();
        $buy = $this->signal('BUY', 2.0);
        $decision = app(DecisionEngine::class)->evaluate($buy);
        $this->assertContains('SYMBOL_POSITION_HELD', $decision->reasons);
        $this->assertContains('EXISTING_SYMBOL_EXPOSURE', $decision->reasons);
        $this->assertSame($trade->id, $decision->context['selection']['held_trade']['id']);
        $this->assertSame('open', $trade->fresh()->status);
        $this->assertDatabaseCount('paper_trades', 1);
        $this->assertDatabaseCount('ml_observations', 2);
    }

    public function test_waiting_opposite_is_rechecked_with_fresh_entry_after_position_closes(): void
    {
        $sell = $this->signal('SELL', .7, ['take_profits' => [3295]]);
        $this->travel(4)->seconds();
        // Keep R/R valid for this short plan.
        $sell->update(['stop_loss' => 3302]);
        app(DecisionEngine::class)->evaluate($sell);
        $buy = $this->signal('BUY', 2.0, ['entry_min' => 3294, 'stop_loss' => 3290]);
        app(DecisionEngine::class)->evaluate($buy);
        $this->travel(4)->seconds();
        app(MarketData::class)->record(['symbol' => 'XAUUSD', 'bid' => 3295, 'ask' => 3295, 'market_open' => true, 'is_historical' => false, 'source' => 'test', 'recorded_at' => now()]);
        $this->assertSame('closed', PaperTrade::where('signal_id', $sell->id)->firstOrFail()->status);
        $this->assertSame('open', PaperTrade::where('signal_id', $buy->id)->firstOrFail()->status);
        $this->assertSame(1, PaperTrade::where('status', 'open')->where('mode', 'paper')->count());
    }

    public function test_arbitration_cannot_bypass_risk_or_revive_cancelled_signal(): void
    {
        $this->signal('SELL', .7);
        $this->signal('BUY', 2, ['status' => 'cancelled']);
        RiskProfile::current()->update(['emergency_stop' => true]);
        $this->travel(4)->seconds();
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertCount(1, $decisions);
        $this->assertContains('EMERGENCY_STOP', collect($decisions)->first()->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_sell_50_then_buy_100_records_both_paths_and_pullback_without_double_counting_profit(): void
    {
        config(['trading.trajectory.horizon_seconds' => 60, 'trading.trajectory.checkpoints_seconds' => [20, 40, 60]]);
        $at = now();
        $quote = MarketSnapshot::latestCurrent('XAUUSD');
        $sell = $this->signal('SELL', overrides: ['take_profits' => [3294.95]]);
        $buy = $this->signal('BUY', overrides: ['stop_loss' => 3297, 'take_profits' => [3310.05]]);
        $patientBuy = $this->signal('BUY', overrides: ['stop_loss' => 3294, 'take_profits' => [3310.05]]);
        $sellObs = app(ObservationRecorder::class)->capture($sell, $sell, $quote, $at);
        $buyObs = app(ObservationRecorder::class)->capture($buy, $buy, $quote, $at);
        $patientObs = app(ObservationRecorder::class)->capture($patientBuy, $patientBuy, $quote, $at);
        $this->quote($at->copy()->addSeconds(20), 3294.95);
        $this->quote($at->copy()->addSeconds(40), 3310.05);
        $this->quote($at->copy()->addSeconds(60), 3310.05);
        app(SignalTrajectory::class)->update($buyObs);
        $this->assertArrayNotHasKey('100', $buyObs->fresh()->path_metrics['milestones']);
        $this->travel(61)->seconds();
        foreach ([$sellObs, $buyObs, $patientObs] as $o) {
            app(SignalTrajectory::class)->update($o);
            app(OutcomeLabeler::class)->label($o);
        }
        $this->assertSame('COMPLETE', $sellObs->fresh()->path_state);
        $this->assertSame('COMPLETE', $buyObs->fresh()->path_state);
        $this->assertEqualsWithDelta(50, $sellObs->fresh()->path_metrics['observed_favourable_pips'], 1e-7);
        $this->assertEqualsWithDelta(100, $buyObs->fresh()->path_metrics['observed_favourable_pips'], 1e-7);
        $this->assertEqualsWithDelta(51, $buyObs->fresh()->path_metrics['milestones'][100]['adverse_before_pips'], 1e-7);
        $this->assertTrue($buyObs->fresh()->path_metrics['milestones'][100]['initial_stop_already_hit']);
        $this->assertLessThan(0, $buyObs->fresh()->outcome['net_r']);
        $this->assertGreaterThan(0, $sellObs->fresh()->outcome['net_r']);
        $this->assertGreaterThan(0, $patientObs->fresh()->outcome['net_r']);
        $this->assertFalse($patientObs->fresh()->path_metrics['milestones'][100]['initial_stop_already_hit']);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->actingAs(User::factory()->create())->get('/signals/'.$buy->id)->assertOk()->assertSee('Bu foydadan OLDIN urilgan');
        $this->get('/channels/'.$buy->channel_id)->assertOk()->assertSee('Qisqa foyda va keyingi katta yurish');
    }

    public function test_trajectory_with_gaps_is_not_a_complete_winner_or_fabricated_milestone(): void
    {
        config(['trading.trajectory.horizon_seconds' => 60]);
        $at = now();
        $signal = $this->signal('BUY');
        $quote = MarketSnapshot::latestCurrent('XAUUSD');
        $observation = app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at);
        $this->quote($at->copy()->addSeconds(60), 3310.05);
        $this->travel(61)->seconds();
        app(SignalTrajectory::class)->update($observation);
        $this->assertSame('DATA_GAP', $observation->fresh()->path_state);
        $this->assertFalse($observation->fresh()->path_metrics['covered']);
        $this->assertEqualsWithDelta(100, $observation->fresh()->path_metrics['observed_favourable_pips'], 1e-7);
    }

    public function test_higher_raw_expectation_can_lose_selection_due_to_prior_adverse_path_and_holding_time(): void
    {
        config(['trading.arbitration.min_history' => 1]);
        $sell = $this->signal('SELL', 1.0);
        $buy = $this->signal('BUY', 1.2);
        $quote = MarketSnapshot::latestCurrent('XAUUSD');
        foreach ([[$sell, -.1, 60], [$buy, -2.5, 3600]] as [$candidate, $mae, $seconds]) {
            $at = now()->subHours(2);
            $message = TelegramMessage::create(['channel_id' => $candidate->channel_id, 'telegram_message_id' => 2, 'raw_text' => 'historical test', 'latest_text' => '', 'raw_json' => [], 'posted_at' => $at, 'is_historical' => true]);
            $past = $candidate->replicate();
            $past->fill(['message_id' => $message->id, 'signal_time' => $at, 'fingerprint' => (string) Str::uuid(), 'status' => 'historical']);
            $past->save();
            MlObservation::create(['signal_id' => $past->id, 'channel_id' => $past->channel_id, 'snapshot_id' => $quote->id, 'feature_version' => MarketFeatures::VERSION, 'features' => [], 'context' => [],
                'plan' => ['symbol' => 'XAUUSD', 'direction' => $past->direction, 'family' => 'fixed_tp', 'horizon_seconds' => 3600], 'origin' => 'historical_replay', 'observed_at' => $at,
                'label_end_at' => $at->copy()->addSeconds($seconds), 'state' => 'COMPLETE', 'outcome' => ['net_r' => 1, 'mae_r' => $mae, 'entry_at' => $at->toIso8601String()]]);
        }
        $this->travel(4)->seconds();
        $decisions = app(DecisionEngine::class)->evaluateSymbol('XAUUSD');
        $this->assertSame($sell->id, PaperTrade::firstOrFail()->signal_id);
        $ranked = collect($decisions[$sell->id]->context['selection']['candidates'])->keyBy('signal_id');
        $this->assertSame('prior_frozen_plan_outcomes', $ranked[$buy->id]['risk_time_source']);
        $this->assertGreaterThan($ranked[$sell->id]['expected_net_r'], $ranked[$buy->id]['expected_net_r']);
        $this->assertLessThan($ranked[$sell->id]['utility_r'], $ranked[$buy->id]['utility_r']);
    }
}
