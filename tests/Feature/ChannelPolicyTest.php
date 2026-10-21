<?php

namespace Tests\Feature;

use App\Models\AgentDecision;
use App\Models\ChannelPolicy;
use App\Models\ClaimCheck;
use App\Models\MarketSnapshot;
use App\Models\MonitoringIssue;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\SignalEvent;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Analytics\ChannelPolicyLearner;
use App\Services\Analytics\ClaimVerifier;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use App\Services\Signals\SignalParser;
use App\Services\Trading\MarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChannelPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['trading.arbitration.collection_seconds' => 0]);
        config(['trading.research.min_train' => 4, 'trading.research.min_validation' => 2, 'trading.research.max_quote_gap_seconds' => 30]);
    }

    private function channel(): TelegramChannel
    {
        return app(ChannelRegistry::class)->add(['username' => 'policy_channel', 'title' => 'Policy research', 'source' => 'manual', 'research_settings' => ['XAUUSD' => ['pip_size' => 0.1, 'horizon_seconds' => 60]]]);
    }

    private function policy(TelegramChannel $channel, string $direction = 'BUY', array $overrides = []): ChannelPolicy
    {
        return ChannelPolicy::create(array_replace(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => $direction, 'status' => 'PAPER_ELIGIBLE', 'fingerprint' => (string) Str::uuid(), 'pip_size' => 0.1, 'sl_pips' => 30, 'target_pips' => 100, 'lock_step_pips' => 50, 'horizon_seconds' => 3600, 'evidence_end_at' => now()->subMinute(), 'expires_at' => now()->addDay(), 'evidence' => ['train_count' => 20, 'validation_count' => 10]], $overrides));
    }

    private function message(TelegramChannel $channel, array $overrides = []): void
    {
        $event = app(EventIngestor::class)->ingest($channel, array_replace(['event_id' => (string) Str::uuid(), 'message_id' => 1, 'kind' => 'new', 'text' => 'GOLD BUY 3299-3301', 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false, 'raw_json' => []], $overrides));
        app(MessageProcessor::class)->process($event->id);
    }

    private function quote(float $bid = 3299.8, float $ask = 3300): void
    {
        app(MarketData::class)->record(['symbol' => 'XAUUSD', 'bid' => $bid, 'ask' => $ask, 'regime' => 'TREND_UP', 'session' => 'LONDON', 'source' => 'test', 'market_open' => true, 'is_historical' => false, 'recorded_at' => now()]);
    }

    public function test_missing_provider_levels_use_learned_policy_without_overwriting_original(): void
    {
        $channel = $this->channel();
        $policy = $this->policy($channel);
        $this->quote();
        $this->message($channel);
        $signal = Signal::first();
        $trade = PaperTrade::where('mode', 'paper')->firstOrFail();
        $this->assertNull($signal->stop_loss);
        $this->assertSame([], $signal->take_profits);
        $this->assertEqualsWithDelta($trade->entry_price - 3, $trade->initial_stop, 1e-8);
        $this->assertNull($trade->take_profit);
        $this->assertSame($policy->id, $trade->management_policy['policy_id']);
        $this->assertSame('channel_policy', $trade->management_policy['target_source']);
        $this->assertLessThanOrEqual(50, $trade->risk_amount);
        $this->actingAs(User::factory()->create())->get('/signals')->assertOk();
        $this->get('/signals/'.$signal->id)->assertOk();
    }

    public function test_100_pips_protects_50_then_150_protects_100_and_never_loosens(): void
    {
        $channel = $this->channel();
        $this->policy($channel);
        $this->quote();
        $this->message($channel);
        $trade = PaperTrade::firstOrFail();
        $entry = $trade->entry_price;
        $this->quote($entry + 10, $entry + 10.2);
        $this->assertEqualsWithDelta($entry + 5, $trade->fresh()->stop_loss, 1e-8);
        $this->assertSame('open', $trade->fresh()->status);
        $this->quote($entry + 15, $entry + 15.2);
        $this->assertEqualsWithDelta($entry + 10, $trade->fresh()->stop_loss, 1e-8);
        $this->quote($entry + 12, $entry + 12.2);
        $this->assertEqualsWithDelta($entry + 10, $trade->fresh()->stop_loss, 1e-8);
        $this->quote($entry + 9.8, $entry + 10);
        $this->assertSame('closed', $trade->fresh()->status);
        $this->assertSame('PROFIT_LOCK_EXIT', $trade->fresh()->close_reason);
        $this->assertGreaterThan(0, $trade->fresh()->realized_pnl);
        $this->assertSame(2, $trade->events()->where('type', 'PROFIT_LOCK')->count());
    }

    public function test_short_trade_protects_profit_in_the_opposite_price_direction(): void
    {
        $channel = $this->channel();
        $this->policy($channel, 'SELL');
        $this->quote(3300, 3300.2);
        $this->message($channel, ['text' => 'GOLD SELL 3299-3301']);
        $trade = PaperTrade::firstOrFail();
        $entry = $trade->entry_price;
        $this->quote($entry - 10.2, $entry - 10);
        $this->assertEqualsWithDelta($entry - 5, $trade->fresh()->stop_loss, 1e-8);
        $this->assertSame('open', $trade->fresh()->status);
    }

    public function test_provider_levels_are_preserved_even_when_channel_policy_exists(): void
    {
        $channel = $this->channel();
        $this->policy($channel);
        $this->quote();
        $this->message($channel, ['text' => 'GOLD BUY 3299-3301 SL 3295 TP 3330']);
        $trade = PaperTrade::firstOrFail();
        $this->assertEquals(3295, $trade->initial_stop);
        $this->assertEquals(3330, $trade->take_profit);
        $this->assertSame('provider', $trade->management_policy['stop_source']);
    }

    public function test_learned_levels_do_not_bypass_emergency_stop(): void
    {
        $channel = $this->channel();
        $this->policy($channel);
        $this->quote();
        RiskProfile::current()->update(['emergency_stop' => true]);
        $this->message($channel);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertContains('EMERGENCY_STOP', AgentDecision::first()->reasons);
    }

    public function test_future_or_expired_learning_is_not_used_for_current_signal(): void
    {
        $channel = $this->channel();
        $this->policy($channel, overrides: ['evidence_end_at' => now()->addHour()]);
        $this->quote();
        $this->message($channel);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertContains('CHANNEL_POLICY_NOT_LEARNED', AgentDecision::first()->reasons);
        $this->policy($channel, overrides: ['expires_at' => now()->subMinute()]);
        $this->message($channel, ['message_id' => 2]);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_provider_profit_lock_instruction_uses_pips_and_target_references(): void
    {
        $parser = app(SignalParser::class);
        $this->assertSame('PROFIT_LOCK', $parser->parse('Bez ubitka 50 pips')['actions'][0]['type']);
        $this->assertSame(1, $parser->parse('Bez ubitka TP2')['actions'][0]['target_index']);
        $channel = $this->channel();
        $this->quote();
        $this->message($channel, ['text' => 'GOLD BUY 3299-3301 SL 3295 TP 3330']);
        $trade = PaperTrade::first();
        $entry = $trade->entry_price;
        $this->quote($entry + 10, $entry + 10.2);
        $this->message($channel, ['message_id' => 2, 'text' => 'Bez ubitka 50 pips', 'reply_to_message_id' => 1]);
        $this->assertEqualsWithDelta($entry + 5, $trade->fresh()->stop_loss, 1e-8);
    }

    private function historicalEpisodes(TelegramChannel $channel, bool $badValidation = false, bool $sparse = false): void
    {
        for ($i = 0; $i < 6; $i++) {
            $start = now()->subHours(2)->addMinutes($i * 5);
            TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => $i + 1, 'raw_text' => 'GOLD BUY 3375-3378', 'latest_text' => 'GOLD BUY 3375-3378', 'raw_json' => [], 'posted_at' => $start, 'is_historical' => true]);
            $prices = $badValidation && $i >= 4 ? [3376, 3372, 3370, 3369] : [3376, 3374, 3386.35, 3384];
            foreach ($prices as $j => $bid) {
                if ($sparse && $j > 0 && $j < 3) {
                    continue;
                }
                MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => $bid, 'ask' => $bid + 0.3, 'source' => 'test_history', 'recorded_at' => $start->copy()->addSeconds($j * 20), 'is_historical' => true]);
            }
        }
    }

    public function test_learner_derives_channel_levels_from_prices_and_reserves_chronological_validation(): void
    {
        $channel = $this->channel();
        $this->historicalEpisodes($channel);
        $learner = app(ChannelPolicyLearner::class);
        $policy = $learner->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('PAPER_ELIGIBLE', $policy->status);
        $this->assertSame(4, $policy->evidence['train_count']);
        $this->assertSame(2, $policy->evidence['validation_count']);
        $this->assertGreaterThan(20, $policy->sl_pips);
        $this->assertGreaterThan(90, $policy->target_pips);
        $this->assertGreaterThan(0, $policy->evidence['validation']['expectancy_r']);
        $this->assertSame($policy->id, $learner->fit($channel, 'XAUUSD', 'BUY')->id);
        $this->assertDatabaseCount('channel_policies', 1);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_good_training_with_losing_holdout_is_rejected(): void
    {
        $channel = $this->channel();
        $this->historicalEpisodes($channel, badValidation: true);
        $policy = app(ChannelPolicyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('REJECTED', $policy->status);
        $this->assertLessThan(0, $policy->evidence['validation']['expectancy_r']);
    }

    public function test_sparse_price_history_does_not_generate_a_usable_policy(): void
    {
        $channel = $this->channel();
        $this->historicalEpisodes($channel, sparse: true);
        $policy = app(ChannelPolicyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('INSUFFICIENT_DATA', $policy->status);
        $this->assertNull($policy->sl_pips);
        $this->assertEquals(6, $policy->evidence['omitted']['missing_coverage']);
    }

    public function test_history_edited_before_first_observation_is_excluded_from_learning(): void
    {
        $channel = $this->channel();
        $this->historicalEpisodes($channel);
        TelegramMessage::oldest('id')->first()->update(['raw_json' => ['edit_date' => now()->toIso8601String()]]);
        $policy = app(ChannelPolicyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('INSUFFICIENT_DATA', $policy->status);
        $this->assertSame(1, $policy->evidence['omitted']['edited_before_observation']);
    }

    public function test_training_windows_overlapping_validation_are_purged(): void
    {
        $channel = $this->channel();
        $start = now()->subHour();
        for ($i = 0; $i < 6; $i++) {
            TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => $i + 1, 'raw_text' => 'GOLD BUY 3375-3378', 'latest_text' => 'GOLD BUY 3375-3378', 'raw_json' => [], 'posted_at' => $start->copy()->addSeconds($i * 20), 'is_historical' => true]);
        }
        for ($i = 0; $i <= 16; $i++) {
            MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => 3376 + $i * 0.01, 'ask' => 3376.3 + $i * 0.01, 'source' => 'test_history', 'recorded_at' => $start->copy()->addSeconds($i * 10), 'is_historical' => true]);
        }
        $policy = app(ChannelPolicyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('INSUFFICIENT_DATA', $policy->status);
        $this->assertGreaterThan(0, $policy->evidence['purged_overlaps']);
        $this->assertSame(1, $policy->evidence['train_count']);
    }

    public function test_missing_tp_trade_has_an_explicit_policy_time_exit(): void
    {
        $channel = $this->channel();
        $this->policy($channel, overrides: ['horizon_seconds' => 60]);
        $this->quote();
        $this->message($channel);
        $this->travel(61)->seconds();
        $this->quote(3302, 3302.2);
        $trade = PaperTrade::first();
        $this->assertSame('closed', $trade->status);
        $this->assertSame('POLICY_TIME_EXIT', $trade->close_reason);
    }

    private function claimScenario(bool $sparse = false): TelegramChannel
    {
        $channel = $this->channel();
        $start = now()->subMinutes(5);
        $this->message($channel, ['is_historical' => true, 'posted_at' => $start->toIso8601String(), 'occurred_at' => $start->toIso8601String()]);
        for ($i = 0; $i <= 15; $i++) {
            if ($sparse && $i !== 0 && $i !== 15) {
                continue;
            }
            $bid = $i === 0 ? 3299.8 : 3300 + 13 * $i / 15;
            MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => $bid, 'ask' => $bid + 0.2, 'source' => 'test_history', 'recorded_at' => $start->copy()->addSeconds($i * 20), 'is_historical' => true]);
        }
        // A later rally must not be used to verify the earlier report.
        MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => 3400, 'ask' => 3400.2, 'source' => 'test_history', 'recorded_at' => now()->addMinute(), 'is_historical' => true]);
        $this->message($channel, ['message_id' => 2, 'text' => '150 pips', 'reply_to_message_id' => 1]);

        return $channel;
    }

    public function test_150_pips_claim_is_compared_with_130_observed_and_20_difference(): void
    {
        $channel = $this->claimScenario();
        $check = ClaimCheck::firstOrFail();
        $this->assertSame('OBSERVED_SHORTFALL', $check->status);
        $this->assertEquals(150, $check->claimed_pips);
        $this->assertEqualsWithDelta(130, $check->observed_pips, 0.001);
        $this->assertEqualsWithDelta(20, $check->shortfall_pips, 0.001);
        $issue = MonitoringIssue::where('code', 'CLAIM_SHORTFALL')->firstOrFail();
        $this->assertEquals(130, $issue->last_evidence['observed_pips']);
        $this->assertEquals(20, $issue->last_evidence['difference_pips']);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->actingAs(User::factory()->create())->get('/channels/'.$channel->id)->assertOk()->assertSee('KUZATILGAN HARAKAT KAMROQ')->assertSee('130');
    }

    public function test_claim_shortfall_with_missing_ticks_remains_inconclusive(): void
    {
        $this->claimScenario(sparse: true);
        $check = ClaimCheck::firstOrFail();
        $this->assertSame('DATA_GAP', $check->status);
        $this->assertEquals(130, $check->observed_pips);
        $this->assertFalse($check->evidence['covered']);
        $this->assertDatabaseMissing('monitoring_issues', ['code' => 'CLAIM_SHORTFALL']);
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'CLAIM_DATA_GAP', 'kind' => 'QUESTION']);
    }

    public function test_claim_rechecks_preserve_previous_evidence_and_are_idempotent(): void
    {
        $channel = $this->claimScenario();
        $event = SignalEvent::where('type', 'RESULT_CLAIM')->firstOrFail();
        $verifier = app(ClaimVerifier::class);
        $verifier->verify($event);
        $this->assertDatabaseCount('claim_checks', 1);
        MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => 3314, 'ask' => 3314.2, 'source' => 'test_backfill', 'recorded_at' => now()->subSeconds(10), 'is_historical' => true]);
        $verifier->channel($channel);
        $this->assertDatabaseCount('claim_checks', 2);
        $this->assertEquals(130, ClaimCheck::oldest('id')->first()->observed_pips);
        $this->assertEquals(140, ClaimCheck::latest('id')->first()->observed_pips);
    }

    public function test_research_endpoint_records_settings_and_reports_insufficient_data(): void
    {
        $channel = $this->channel();
        $this->actingAs(User::factory()->create())->post('/channels/'.$channel->id.'/research', ['symbol' => 'XAUUSD', 'direction' => 'BUY', 'pip_size' => 0.1, 'horizon_seconds' => 60])->assertRedirect();
        $this->assertDatabaseHas('channel_policies', ['status' => 'INSUFFICIENT_DATA']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.research_settings']);
        $this->assertEquals(0.1, $channel->fresh()->research_settings['XAUUSD']['pip_size']);
    }
}
