<?php

namespace Tests\Feature;

use App\Models\ChannelStrategy;
use App\Models\MarketSnapshot;
use App\Models\MlObservation;
use App\Models\PaperTrade;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\User;
use App\Services\Analytics\ChannelEvidence;
use App\Services\Analytics\ChannelStrategyLearner;
use App\Services\Learning\ObservationRecorder;
use App\Services\Learning\OutcomeLabeler;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\ExitPlans;
use App\Services\Trading\MarketData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PersonalChannelStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['trading.entries.enabled' => false]);
        $this->travelTo(now()->startOfMinute());
        config(['trading.arbitration.collection_seconds' => 0, 'trading.strategies.min_train' => 6, 'trading.strategies.min_validation' => 2, 'trading.strategies.min_test' => 2, 'trading.strategies.min_exclusion_test' => 2, 'trading.strategies.min_exclusion_days' => 1]);
    }

    private function channel(): TelegramChannel
    {
        $n = TelegramChannel::count() + 1;

        return app(ChannelRegistry::class)->add(['username' => 'personal_'.$n, 'title' => 'Personal '.$n, 'source' => 'manual', 'research_settings' => ['XAUUSD' => ['pip_size' => .1, 'horizon_seconds' => 60]]]);
    }

    private function quote($at, float $price): MarketSnapshot
    {
        return MarketSnapshot::create(['symbol' => 'XAUUSD', 'bid' => $price, 'ask' => $price, 'source' => 'test', 'recorded_at' => $at, 'market_open' => true, 'is_historical' => false]);
    }

    private function signal(TelegramChannel $channel, $at, string $direction = 'BUY', bool $historical = true): Signal
    {
        $message = TelegramMessage::create(['channel_id' => $channel->id, 'telegram_message_id' => 1 + (int) $channel->messages()->max('telegram_message_id'), 'raw_text' => 'GOLD '.$direction.' 3299-3301 SL '.($direction === 'BUY' ? '3297 TP 3312.05 TP2 3320.05' : '3303 TP 3287.95 TP2 3279.95'), 'latest_text' => '', 'raw_json' => [], 'posted_at' => $at, 'is_historical' => $historical]);

        return Signal::create(['channel_id' => $channel->id, 'message_id' => $message->id, 'symbol' => 'XAUUSD', 'direction' => $direction, 'entry_type' => 'RANGE', 'entry_min' => 3299, 'entry_max' => 3301, 'stop_loss' => $direction === 'BUY' ? 3297 : 3303, 'take_profits' => $direction === 'BUY' ? [3312.05, 3320.05] : [3287.95, 3279.95], 'confidence' => .99, 'status' => $historical ? 'historical' : 'ready', 'signal_time' => $at, 'fingerprint' => (string) Str::uuid()]);
    }

    private function history(TelegramChannel $channel, string $pattern, int $count = 10, string $direction = 'BUY', bool $gaps = false, int $offsetHours = 0): void
    {
        $start = now()->subHours(12 - $channel->id * 2)->addHours($offsetHours);
        for ($i = 0; $i < $count; $i++) {
            $at = $start->copy()->addMinutes($i * 5);
            $quote = $this->quote($at, 3300);
            $signal = $this->signal($channel, $at, $direction);
            app(ObservationRecorder::class)->capture($signal, $signal, $quote, $at, ['horizon_seconds' => 60], 'historical_replay');
            $kind = $pattern === 'shift' && $i >= 6 ? 'long' : $pattern;
            $moves = match ($kind) {
                'short', 'shift' => [50, -41, -41], 'long' => [50, 100, 200], default => [-41, -41, -41]
            };
            foreach ($moves as $j => $pips) {
                if ($gaps && $j < 2) {
                    continue;
                }
                $sign = $direction === 'BUY' ? 1 : -1;
                $this->quote($at->copy()->addSeconds(($j + 1) * 20), 3300 + $sign * (.05 + $pips * .1));
            }
        }
    }

    private function approved(TelegramChannel $channel, string $direction, string $key): ChannelStrategy
    {
        $channel->update(['trade_state' => 'PERSONALIZED']);

        return ChannelStrategy::create(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => $direction, 'fingerprint' => (string) Str::uuid(), 'status' => 'PAPER_ELIGIBLE', 'parameters' => app(ExitPlans::class)->variants(60, .1)[$key], 'report' => [], 'evidence_end_at' => now()->subHour(), 'expires_at' => now()->addDay()]);
    }

    public function test_video_only_post_is_skipped_but_written_caption_can_create_a_signal(): void
    {
        $channel = $this->channel();
        $this->quote(now(), 3300);
        foreach (['', 'GOLD BUY 3299-3301 SL 3297 TP 3312.05'] as $i => $text) {
            $event = app(EventIngestor::class)->ingest($channel, ['event_id' => (string) Str::uuid(), 'message_id' => $i + 1, 'kind' => 'new', 'text' => $text, 'raw_json' => ['media' => ['document' => ['mime_type' => 'video/mp4']]], 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false]);
            app(MessageProcessor::class)->process($event->id);
        }
        $this->assertDatabaseHas('telegram_messages', ['telegram_message_id' => 1, 'message_type' => 'SKIPPED_VIDEO', 'processing_status' => 'processed']);
        $this->assertDatabaseCount('signals', 1);
        $this->assertDatabaseCount('paper_trades', 1);
        $this->assertSame(2, app(ChannelEvidence::class)->inspect($channel)['counts']['video_posts_skipped']);
    }

    public function test_different_channels_learn_different_exits_and_keep_provider_originals(): void
    {
        $short = $this->channel();
        $this->history($short, 'short');
        $long = $this->channel();
        $this->history($long, 'long');
        $learner = app(ChannelStrategyLearner::class);
        $shortPolicy = $learner->fit($short, 'XAUUSD', 'BUY');
        $longPolicy = $learner->fit($long, 'XAUUSD', 'BUY');
        $this->assertSame('PAPER_ELIGIBLE', $shortPolicy->status);
        $this->assertSame('tp_50', $shortPolicy->parameters['key']);
        $this->assertSame('provider_last', $longPolicy->parameters['key']);
        $this->assertSame(6, $shortPolicy->report['train_count']);
        $this->assertSame(2, $shortPolicy->report['validation_count']);
        $this->assertSame(2, $shortPolicy->report['test_count']);
        $this->assertSame($shortPolicy->id, $learner->fit($short, 'XAUUSD', 'BUY')->id);
        $this->quote(now(), 3300);
        $signal = $this->signal($short->fresh(), now(), historical: false);
        app(DecisionEngine::class)->evaluate($signal);
        $this->assertEqualsWithDelta(3305.05, PaperTrade::firstOrFail()->take_profit, 1e-8);
        $this->assertSame([3312.05, 3320.05], $signal->fresh()->take_profits);
        $this->assertSame('PERSONALIZED', $short->fresh()->trade_state);
        $this->actingAs(User::factory()->create())->get('/channels/'.$short->id)->assertOk()->assertSee('Kanalga individual yondashuv')->assertSee('50 pipsda yopish');
    }

    public function test_good_train_winner_cannot_be_reselected_from_test_and_baseline_is_prespecified(): void
    {
        $channel = $this->channel();
        $this->history($channel, 'shift');
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('tp_50', $policy->report['selected_in_train']);
        $this->assertSame('provider_first', $policy->parameters['key']);
        $this->assertSame('PRESPECIFIED_BASELINE_RETAINED', $policy->report['selection_reason']);
    }

    public function test_unprofitable_channel_stops_new_trades_but_still_records_and_monitors_signals(): void
    {
        $channel = $this->channel();
        $this->history($channel, 'bad');
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('UNPROFITABLE', $policy->status);
        $this->assertSame('EXCLUDED', $channel->fresh()->trade_state);
        $this->assertTrue($channel->fresh()->enabled);
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'CHANNEL_TESTS_UNPROFITABLE', 'channel_id' => $channel->id]);
        $this->quote(now(), 3300);
        $signal = $this->signal($channel->fresh(), now(), historical: false);
        $decision = app(DecisionEngine::class)->evaluate($signal);
        $this->assertContains('CHANNEL_UNPROFITABLE_MONITOR_ONLY', $decision->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertDatabaseCount('ml_observations', 11);
        $this->assertDatabaseHas('audit_logs', ['action' => 'channel.strategy_evaluated']);
        $this->actingAs(User::factory()->create())->get('/channels/'.$channel->id)->assertOk()->assertSee('savdo bloklangan')->assertSee('Davom etmoqda');
    }

    public function test_missing_coverage_or_small_samples_do_not_label_a_channel_useless(): void
    {
        $small = $this->channel();
        $this->history($small, 'bad', 2);
        $this->assertSame('INSUFFICIENT_DATA', app(ChannelStrategyLearner::class)->fit($small, 'XAUUSD', 'BUY')->status);
        $this->assertSame('PAPER_BASELINE', $small->fresh()->trade_state);
        $gaps = $this->channel();
        $this->history($gaps, 'bad', gaps: true);
        $policy = app(ChannelStrategyLearner::class)->fit($gaps, 'XAUUSD', 'BUY');
        $this->assertSame('INSUFFICIENT_DATA', $policy->status);
        $this->assertSame(10, $policy->report['omitted']);
        $this->assertSame('PAPER_BASELINE', $gaps->fresh()->trade_state);
    }

    public function test_shadow_only_failed_methods_pause_trading_before_permanent_exclusion_evidence(): void
    {
        config(['trading.strategies.min_exclusion_test' => 20]);
        $channel = $this->channel();
        $this->history($channel, 'bad');
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('SHADOW_ONLY', $policy->status);
        $this->assertSame('MONITOR_ONLY', $channel->fresh()->trade_state);
        $this->quote(now(), 3300);
        $decision = app(DecisionEngine::class)->evaluate($this->signal($channel->fresh(), now(), historical: false));
        $this->assertContains('CHANNEL_RESEARCH_MONITOR_ONLY', $decision->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_old_winning_history_does_not_automatically_restore_an_excluded_channel(): void
    {
        $channel = $this->channel();
        $this->history($channel, 'short');
        $channel->update(['trade_state' => 'EXCLUDED']);
        ChannelStrategy::create(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'fingerprint' => 'bad-original', 'status' => 'UNPROFITABLE', 'parameters' => null, 'report' => [], 'evidence_end_at' => now()->subDay()]);
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('RECOVERY_SHADOW', $policy->status);
        $this->assertSame('EXCLUDED', $channel->fresh()->trade_state);
        $this->assertSame('AWAITING_NEW_POST_EXCLUSION_TEST', $policy->report['selection_reason']);
    }

    public function test_save_policy_executes_once_and_counterfactual_label_matches_paper_net_result(): void
    {
        foreach (['BUY', 'SELL'] as $direction) {
            $channel = $this->channel();
            $this->approved($channel, $direction, 'save_50');
            $this->quote(now(), 3300);
            $signal = $this->signal($channel, now(), $direction, false);
            app(DecisionEngine::class)->evaluate($signal);
            $trade = PaperTrade::where('signal_id', $signal->id)->firstOrFail();
            $this->assertNull($trade->take_profit);
            $sign = $direction === 'BUY' ? 1 : -1;
            foreach ([[20, 50], [10, 60], [10, -1]] as [$seconds, $pips]) {
                $this->travel($seconds)->seconds();
                app(MarketData::class)->record(['symbol' => 'XAUUSD', 'bid' => $trade->entry_price + $sign * $pips * .1, 'ask' => $trade->entry_price + $sign * $pips * .1, 'market_open' => true, 'is_historical' => false, 'recorded_at' => now(), 'source' => 'test']);
                if ($pips >= 50) {
                    $this->assertEquals(.5, $trade->fresh()->remaining_fraction);
                }
            }
            $this->assertSame('closed', $trade->fresh()->status);
            $this->assertSame(1, $trade->events()->where('type', 'POLICY_SAVE')->count());
            $observation = MlObservation::where('signal_id', $signal->id)->firstOrFail();
            app(OutcomeLabeler::class)->label($observation);
            $this->assertSame('COMPLETE', $observation->fresh()->state);
            $this->assertEqualsWithDelta($trade->fresh()->realized_pnl / $trade->risk_amount, $observation->fresh()->outcome['net_r'], 1e-7);
            $this->assertSame('channel_strategy:save_50', $observation->plan['family']);
        }
    }

    public function test_bad_buy_direction_can_be_blocked_while_profitable_sell_uses_its_own_policy(): void
    {
        $channel = $this->channel();
        $this->history($channel, 'bad');
        $this->history($channel, 'long', direction: 'SELL', offsetHours: 2);
        $learner = app(ChannelStrategyLearner::class);
        $this->assertSame('UNPROFITABLE', $learner->fit($channel, 'XAUUSD', 'BUY')->status);
        $this->assertSame('PAPER_ELIGIBLE', $learner->fit($channel, 'XAUUSD', 'SELL')->status);
        $this->assertSame('SELECTIVE', $channel->fresh()->trade_state);
        $this->quote(now(), 3300);
        $buy = $this->signal($channel->fresh(), now(), historical: false);
        $this->assertContains('CHANNEL_DIRECTION_UNPROFITABLE', app(DecisionEngine::class)->evaluate($buy)->reasons);
        $sell = $this->signal($channel->fresh(), now(), 'SELL', false);
        $this->assertSame('PAPER_OPENED', app(DecisionEngine::class)->evaluate($sell)->action);
        $this->assertSame('SELL', PaperTrade::firstOrFail()->direction);
        $this->assertDatabaseCount('paper_trades', 1);
    }

    public function test_only_new_independent_post_exclusion_evidence_can_restore_a_channel(): void
    {
        $channel = $this->channel();
        $this->history($channel, 'short');
        $channel->update(['trade_state' => 'EXCLUDED']);
        ChannelStrategy::create(['channel_id' => $channel->id, 'symbol' => 'XAUUSD', 'direction' => 'BUY', 'fingerprint' => 'old-exclusion', 'status' => 'UNPROFITABLE', 'parameters' => null, 'report' => [], 'evidence_end_at' => now()->subDays(2), 'created_at' => now()->subDay()]);
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('PAPER_ELIGIBLE', $policy->status);
        $this->assertSame('PERSONALIZED', $channel->fresh()->trade_state);
    }

    public function test_overlapping_signal_windows_are_not_counted_as_independent_strategy_evidence(): void
    {
        config(['trading.signal_max_age_seconds' => 300]);
        $channel = $this->channel();
        $this->history($channel, 'bad');
        $policy = app(ChannelStrategyLearner::class)->fit($channel, 'XAUUSD', 'BUY');
        $this->assertSame('INSUFFICIENT_DATA', $policy->status);
        $this->assertSame(5, $policy->report['sampled_overlaps_omitted']);
        $this->assertSame(5, $policy->report['observations']);
        $this->assertNotEquals('EXCLUDED', $channel->fresh()->trade_state);
    }
}
