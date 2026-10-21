<?php

namespace Tests\Feature;

use App\Models\ChannelStrategy;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Analytics\ChannelStrategyLearner;
use App\Services\Analytics\EntryResearch;
use App\Services\Learning\ObservationRecorder;
use App\Services\Learning\OutcomeLabeler;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use App\Services\Trading\ChannelRisk;
use App\Services\Trading\EntryPlans;
use App\Services\Trading\MarketData;
use App\Services\Trading\PaperExecution;
use App\Services\Trading\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class EntryManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['trading.arbitration.collection_seconds' => 0, 'trading.strategies.min_train' => 6, 'trading.strategies.min_validation' => 2, 'trading.strategies.min_test' => 2]);
    }

    private function channel(int $horizon = 3600): TelegramChannel
    {
        return app(ChannelRegistry::class)->add(['username' => 'entry_'.(TelegramChannel::count() + 1), 'title' => 'Entry study', 'source' => 'manual', 'research_settings' => ['XAUUSD' => ['pip_size' => .1, 'horizon_seconds' => $horizon]]]);
    }

    private function policy(TelegramChannel $channel, string $key, string $direction = 'BUY'): ChannelStrategy
    {
        $parameters = app(EntryPlans::class)->variants($channel->research_settings['XAUUSD']['horizon_seconds'], .1)[$key];

        return ChannelStrategy::create(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => $direction, 'fingerprint' => (string) Str::uuid(), 'status' => 'PAPER_ELIGIBLE', 'parameters' => $parameters, 'report' => ['train_count' => 6, 'validation_count' => 2, 'test_count' => 2, 'omitted' => 0, 'variants' => []], 'evidence_end_at' => now()->subMinute(), 'expires_at' => now()->addDay()]);
    }

    private function quote(float $price): void
    {
        app(MarketData::class)->record(['symbol' => 'XAUUSD', 'bid' => $price, 'ask' => $price, 'source' => 'test', 'recorded_at' => now(), 'market_open' => true, 'is_historical' => false]);
    }

    private function message(TelegramChannel $channel, int $id = 1, string $text = 'GOLD BUY 3294-3301 SL 3290 TP 3325'): Signal
    {
        $event = app(EventIngestor::class)->ingest($channel, ['event_id' => (string) Str::uuid(), 'message_id' => $id, 'kind' => 'new', 'text' => $text, 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false, 'raw_json' => []]);
        app(MessageProcessor::class)->process($event->id);

        return Signal::where('channel_id', $channel->id)->latest('id')->firstOrFail();
    }

    private function episode(TelegramChannel $channel, $at, bool $bad = false, bool $wide = false): MlObservation
    {
        $message = TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => $channel->messages()->count() + 1, 'raw_text' => $wide ? 'GOLD BUY 3294-3301 SL 3290 TP 3325' : 'GOLD BUY 3299-3301 SL 3297 TP 3325', 'latest_text' => '', 'raw_json' => [], 'posted_at' => $at, 'is_historical' => true]);
        $signal = Signal::create(['channel_id' => $channel->id, 'message_id' => $message->id, 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'entry_type' => 'RANGE', 'entry_min' => $wide ? 3294 : 3299, 'entry_max' => 3301, 'stop_loss' => $wide ? 3290 : 3297, 'take_profits' => [3325], 'confidence' => .99, 'signal_time' => $at, 'status' => 'historical', 'fingerprint' => (string) Str::uuid()]);
        $initial = null;
        for ($i = 0; $i <= 12; $i++) {
            $price = $i === 0 ? 3300 : ($i === 1 ? ($bad ? 3288 : 3295) : 3325);
            $tick = MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => $price, 'ask' => $price, 'recorded_at' => $at->copy()->addSeconds($i * 20), 'source' => 'test', 'market_open' => true, 'is_historical' => true]);
            $initial ??= $tick;
        }

        return app(ObservationRecorder::class)->capture($signal, $signal, $initial, $at, ['horizon_seconds' => 60], 'historical_replay');
    }

    public function test_50_pips_adverse_then_250_favourable_compares_loss_and_predefined_alternatives(): void
    {
        $channel = $this->channel(60);
        $observation = $this->episode($channel, now()->subHour());
        $variants = app(EntryPlans::class)->variants(60, .1);
        $study = app(EntryResearch::class)->study($observation, $variants);
        $this->assertNotNull($study);
        $this->assertLessThan(0, $study->report['variants']['provider_first']['net_r']);
        $this->assertGreaterThan(0, $study->report['variants']['touch_sl60__provider_first']['net_r']);
        $wide = $this->episode($channel, now()->subMinutes(30), wide: true);
        $wideStudy = app(EntryResearch::class)->study($wide, $variants);
        $wait = $wideStudy->report['variants']['wait50_sl60__provider_first'];
        $this->assertGreaterThan(0, $wait['net_r']);
        $this->assertEqualsWithDelta(3295.05, $wait['entry'], 1e-7);
        $this->assertNull($study->report['variants']['zone_deep__provider_first']['entry']);
        $this->assertEquals(0, $study->report['variants']['zone_deep__provider_first']['net_r']);
        $this->assertSame($study->id, app(EntryResearch::class)->study($observation, $variants)->id);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->actingAs(User::factory()->create())->get('/signals/'.$observation->signal_id)->assertOk()->assertSee('Qaysi kirish usuli foydali bo‘lardi?');
    }

    public function test_studies_require_full_common_coverage_and_do_not_omit_unfilled_variants(): void
    {
        $channel = $this->channel(60);
        $observation = $this->episode($channel, now()->subHour());
        MarketSnapshot::where('recorded_at', $observation->observed_at->copy()->addSeconds(100))->delete();
        $this->assertNull(app(EntryResearch::class)->study($observation, app(EntryPlans::class)->variants(60, .1)));
        $this->assertDatabaseCount('entry_experiments', 0);
    }

    public function test_joint_policy_is_chosen_on_training_and_rejected_when_later_period_fails(): void
    {
        foreach ([false, true] as $badHoldout) {
            $channel = $this->channel(60);
            for ($i = 0; $i < 10; $i++) {
                $this->episode($channel, now()->subHours(3)->addMinutes($i * 5 + $channel->id * 60), $badHoldout && $i >= 6);
            }
            $strategy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
            $this->assertSame('joint-entry-exit-1', $strategy->report['method']);
            $this->assertSame(10, $strategy->report['complete_episodes']);
            $this->assertSame(2, $strategy->report['test_count']);
            if ($badHoldout) {
                $this->assertSame('SHADOW_ONLY', $strategy->status);
            } else {
                $this->assertSame('PAPER_ELIGIBLE', $strategy->status);
                $this->assertSame(60, $strategy->parameters['stop_pips']);
                $this->assertLessThanOrEqual(1, $strategy->parameters['risk_multiplier']);
            }
        }
    }

    public function test_waits_for_zone_level_and_freezes_stop_instead_of_reanchoring_on_each_quote(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'wait50_sl60__provider_first');
        $this->quote(3300);
        $signal = $this->message($channel);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertEquals(3294, $signal->fresh()->entry_plan['stop_loss']);
        $this->travel(10)->seconds();
        $this->quote(3297);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->travel(10)->seconds();
        $this->quote(3295);
        $trade = PaperTrade::where('mode', 'paper')->firstOrFail();
        $this->assertEquals(3294, $trade->initial_stop);
        $this->assertEquals(3290, $signal->fresh()->stop_loss);
    }

    public function test_ladder_fills_one_net_position_reserves_total_risk_and_counterfactual_matches(): void
    {
        $channel = $this->channel(60);
        $this->policy($channel, 'ladder_sl90__provider_first');
        $this->quote(3300);
        $signal = $this->message($channel);
        $trade = PaperTrade::where('mode', 'paper')->firstOrFail();
        $this->assertCount(3, $trade->entry_legs);
        $this->assertSame(1, collect($trade->entry_legs)->where('status', 'FILLED')->count());
        $this->assertLessThanOrEqual(50, $trade->exposureRisk());
        $reserved = $trade->risk_amount;
        $initialVolume = $trade->volume;
        $this->travel(10)->seconds();
        $this->quote(3297.5);
        $this->travel(10)->seconds();
        $this->quote(3294);
        $trade->refresh();
        $this->assertSame(3, collect($trade->entry_legs)->where('status', 'FILLED')->count());
        $this->assertGreaterThan($initialVolume, $trade->volume);
        $this->assertEquals($reserved, $trade->risk_amount);
        $this->assertSame(1, PaperTrade::where('mode', 'paper')->count());
        $this->assertSame(2, $trade->events()->where('type', 'ENTRY_ADDED')->count());
        $this->assertEqualsWithDelta(-$trade->commission, app(Portfolio::class)->summary()['daily_pnl'], 1e-7);
        $this->travel(10)->seconds();
        $this->quote(3325);
        $this->assertSame('closed', $trade->fresh()->status);
        for ($i = 0; $i < 21; $i++) {
            $this->travel(10)->seconds();
            $this->quote(3325);
        }
        $observation = MlObservation::where('signal_id', $signal->id)->firstOrFail();
        app(OutcomeLabeler::class)->label($observation);
        $this->assertSame('COMPLETE', $observation->fresh()->state);
        $this->assertEqualsWithDelta($trade->fresh()->realized_pnl / $trade->risk_amount, $observation->fresh()->outcome['net_r'], 1e-7);
    }

    public function test_protection_and_emergency_cancel_pending_legs_without_adding_more_volume(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'ladder_sl90__provider_first');
        $this->quote(3300);
        $signal = $this->message($channel);
        $trade = PaperTrade::firstOrFail();
        $volume = $trade->volume;
        $this->travel(10)->seconds();
        $this->quote(3305);
        app(PaperExecution::class)->apply($signal, ['type' => 'BREAK_EVEN']);
        $this->assertSame(2, collect($trade->fresh()->entry_legs)->where('status', 'CANCELLED')->count());
        $this->assertLessThan($trade->risk_amount, $trade->fresh()->exposureRisk());
        $this->travel(10)->seconds();
        $this->quote(3297);
        $this->assertEquals($volume, $trade->fresh()->volume);
        $this->assertSame('closed', $trade->fresh()->status);
        RiskProfile::current()->update(['emergency_stop' => true]);
        $this->message($channel, 2);
        $this->assertSame(1, PaperTrade::count());
    }

    public function test_channel_caps_are_validated_audited_and_minimum_lot_cannot_raise_risk(): void
    {
        $channel = $this->channel();
        $this->actingAs(User::factory()->create());
        $this->post('/channels/'.$channel->id.'/risk', ['max_trade_risk_pct' => 20])->assertSessionHasErrors('max_trade_risk_pct');
        $this->post('/channels/'.$channel->id.'/risk', ['max_trade_risk_pct' => .01, 'max_daily_loss_pct' => .1])->assertRedirect();
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.risk_updated']);
        $this->assertEquals(.01, app(ChannelRisk::class)->limits($channel->fresh(), RiskProfile::current())->max_trade_risk_pct);
        $this->policy($channel, 'ladder_sl90__provider_first');
        $this->quote(3300);
        $signal = $this->message($channel);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertContains('ENTRY_LEG_BELOW_MINIMUM_VOLUME', $signal->decisions()->first()->reasons);
        $this->get('/channels/'.$channel->id)->assertOk()->assertSee('Kanal uchun risk boshqaruvi');
    }

    public function test_sell_ladder_uses_the_opposite_zone_edges_and_common_stop(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'ladder_sl90__provider_first', 'SELL');
        $this->quote(3300);
        $this->message($channel, text: 'GOLD SELL 3299-3306 SL 3310 TP 3275');
        $this->travel(10)->seconds();
        $this->quote(3302.5);
        $this->travel(10)->seconds();
        $this->quote(3306);
        $trade = PaperTrade::firstOrFail();
        $this->assertSame(3, collect($trade->entry_legs)->where('status', 'FILLED')->count());
        $this->assertEquals(3309, $trade->initial_stop);
        $this->assertLessThanOrEqual(50, $trade->risk_amount);
        $this->travel(10)->seconds();
        $this->quote(3275);
        $this->assertSame('closed', $trade->fresh()->status);
        $this->assertGreaterThan(0, $trade->fresh()->realized_pnl);
    }

    public function test_pending_legs_expire_without_entering_late(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'ladder_sl90__provider_first');
        $this->quote(3300);
        $this->message($channel);
        $trade = PaperTrade::firstOrFail();
        $volume = $trade->volume;
        $this->travel(181)->seconds();
        $this->quote(3297.5);
        $this->assertEquals($volume, $trade->fresh()->volume);
        $this->assertSame('open', $trade->fresh()->status);
        $this->assertSame(2, collect($trade->fresh()->entry_legs)->where('status', 'CANCELLED')->count());
        $this->assertSame('ENTRY_WINDOW_EXPIRED', $trade->events()->where('type', 'ENTRIES_CANCELLED')->first()->payload['reason']);
    }

    public function test_emergency_stop_cancels_reserved_entries_but_keeps_the_original_stop(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'ladder_sl90__provider_first');
        $this->quote(3300);
        $this->message($channel);
        $trade = PaperTrade::firstOrFail();
        $volume = $trade->volume;
        RiskProfile::current()->update(['emergency_stop' => true]);
        $this->travel(10)->seconds();
        $this->quote(3297.5);
        $this->assertEquals($volume, $trade->fresh()->volume);
        $this->assertEquals($trade->initial_stop, $trade->fresh()->stop_loss);
        $this->assertSame(2, collect($trade->fresh()->entry_legs)->where('status', 'CANCELLED')->count());
    }

    public function test_channel_daily_loss_stops_that_channel_while_other_channel_keeps_its_limits(): void
    {
        $channel = $this->channel();
        $channel->update(['risk_settings' => ['max_daily_loss_pct' => .1]]);
        $this->policy($channel, 'provider_first');
        $this->quote(3300);
        $this->message($channel);
        app(PaperExecution::class)->closeFraction(PaperTrade::firstOrFail(), 1, 3290, 'TEST_STOP', now());
        $this->assertContains('CHANNEL_MAX_DAILY_LOSS_PCT', app(ChannelRisk::class)->reasons($channel->fresh(), RiskProfile::current()));
        $this->assertSame([], app(ChannelRisk::class)->reasons($this->channel(), RiskProfile::current()));
    }
}
