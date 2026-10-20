<?php

namespace Tests\Feature;

use App\Models\AgentDecision;
use App\Models\MarketSnapshot;
use App\Models\PaperTrade;
use App\Models\RiskProfile;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageEvent;
use App\Models\TradeEvent;
use App\Models\User;
use App\Services\Analytics\Backtester;
use App\Services\Analytics\ChannelAnalytics;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use App\Services\Signals\SignalParser;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\MarketData;
use App\Services\Trading\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SignalIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = "GOLD BUY NOW\n3378-3375\nSL 3370\nTP1 3385\nTP2 3392";

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['trading.gateway_token' => str_repeat('g', 64), 'trading.market_token' => str_repeat('m', 64)]);
    }

    private function channel(string $name = 'gold_research'): TelegramChannel
    {
        return app(ChannelRegistry::class)->add(['username' => $name, 'title' => $name, 'source' => 'telegram', 'telegram_id' => '-100'.random_int(100000, 999999)]);
    }

    private function quote(array $override = []): MarketSnapshot
    {
        return app(MarketData::class)->record(array_replace(['symbol' => 'XAUUSD', 'bid' => 3376, 'ask' => 3376.3, 'regime' => 'TREND_UP', 'session' => 'LONDON', 'source' => 'test', 'recorded_at' => now(), 'market_open' => true, 'is_historical' => false], $override));
    }

    private function data(TelegramChannel $channel, array $overrides = []): array
    {
        return array_replace(['event_id' => (string) Str::uuid(), 'channel_id' => $channel->id, 'telegram_id' => $channel->telegram_id, 'message_id' => 1, 'kind' => 'new', 'text' => self::TEXT, 'raw_json' => ['original' => true], 'reply_to_message_id' => null, 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false], $overrides);
    }

    private function ingest(TelegramChannel $channel, array $overrides = []): ?Signal
    {
        $event = app(EventIngestor::class)->ingest($channel, $this->data($channel, $overrides));
        app(MessageProcessor::class)->process($event->id);

        return Signal::where('channel_id', $channel->id)->latest('id')->first();
    }

    public function test_parser_normalizes_range_alias_and_multiple_targets(): void
    {
        $result = app(SignalParser::class)->parse(self::TEXT);
        $this->assertSame('NEW_SIGNAL', $result['type']);
        $this->assertSame('XAUUSD', $result['signal']['symbol']);
        $this->assertEquals(3375, $result['signal']['entry_min']);
        $this->assertEquals(3378, $result['signal']['entry_max']);
        $this->assertSame([3385.0, 3392.0], $result['signal']['take_profits']);
    }

    public static function ambiguousSignals(): array
    {
        return [['GOLD BUY NOW SL 3370 TP 3385'], ['GOLD BUY 3375 TP 3385'], ['GOLD BUY 3375 SL 3380 TP 3390'], ['GOLD BUY 3375 SL 3370 TP 3360'], ['GOLD BUY SELL 3375 SL 3370 TP 3385'], ['GOLD EURUSD BUY 3375 SL 3370 TP 3385'], ['GOLD BUY 3375 SL 3370 TP 3385 risk 10%'], ['GOLD BUY STOP 3375 SL 3370 TP 3385']];
    }

    #[DataProvider('ambiguousSignals')]
    public function test_ambiguous_or_unsafe_signal_requires_review(string $text): void
    {
        $this->assertSame('REVIEW_REQUIRED', app(SignalParser::class)->parse($text)['type']);
    }

    public function test_parser_detects_combined_partial_and_break_even(): void
    {
        $result = app(SignalParser::class)->parse('Close 30% and move SL to BE.');
        $this->assertSame(['PARTIAL_CLOSE', 'BREAK_EVEN'], array_column($result['actions'], 'type'));
        $this->assertSame(0.3, $result['actions'][0]['fraction']);
    }

    public function test_gateway_rejects_wrong_scope_and_disabled_channels(): void
    {
        $channel = $this->channel();
        $this->getJson('/api/gateway/channels')->assertUnauthorized();
        $this->withToken(str_repeat('m', 64))->getJson('/api/gateway/channels')->assertUnauthorized();
        $channel->update(['enabled' => false]);
        $this->withToken(str_repeat('g', 64))->postJson('/api/gateway/events', $this->data($channel))->assertForbidden();
        $this->assertDatabaseCount('telegram_message_events', 0);
    }

    public function test_event_retries_are_idempotent_and_payload_conflicts_are_rejected(): void
    {
        $channel = $this->channel();
        $this->quote();
        $data = $this->data($channel);
        $this->withToken(str_repeat('g', 64))->postJson('/api/gateway/events', $data)->assertAccepted();
        $event = TelegramMessageEvent::firstOrFail();
        app(MessageProcessor::class)->process($event->id);
        app(MessageProcessor::class)->process($event->id);
        $this->withToken(str_repeat('g', 64))->postJson('/api/gateway/events', $data)->assertOk();
        $this->withToken(str_repeat('g', 64))->postJson('/api/gateway/events', array_replace($data, ['text' => 'changed']))->assertConflict();
        $this->assertDatabaseCount('telegram_messages', 1);
        $this->assertDatabaseCount('signals', 1);
        $this->assertSame(1, PaperTrade::where('mode', 'paper')->count());
        $this->assertSame(1, PaperTrade::where('mode', 'shadow')->count());
    }

    public function test_original_text_and_trade_stops_survive_edits_and_deletion(): void
    {
        $channel = $this->channel();
        $this->quote();
        $signal = $this->ingest($channel);
        $this->ingest($channel, ['kind' => 'edit', 'text' => str_replace('3370', '3360', self::TEXT)]);
        $this->assertSame(self::TEXT, TelegramMessage::first()->raw_text);
        $this->assertStringContainsString('3360', TelegramMessage::first()->latest_text);
        $this->assertSame('review', $signal->fresh()->status);
        $this->assertEquals(3370, PaperTrade::first()->stop_loss);
        $this->ingest($channel, ['kind' => 'delete', 'text' => '']);
        $this->assertNotNull(TelegramMessage::first()->deleted_at);
        $this->assertSame(self::TEXT, TelegramMessage::first()->raw_text);
        $this->assertSame('deleted', $signal->fresh()->status);
        $this->assertDatabaseCount('telegram_message_events', 3);
    }

    public function test_historical_signals_never_open_current_trades(): void
    {
        $channel = $this->channel();
        $this->quote();
        $signal = $this->ingest($channel, ['is_historical' => true]);
        $this->assertSame('historical', $signal->status);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->assertContains('HISTORICAL_SIGNAL_BACKTEST_ONLY', AgentDecision::first()->reasons);
    }

    public function test_kill_switch_blocks_entries_but_existing_stops_still_close(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        RiskProfile::current()->update(['emergency_stop' => true]);
        $this->ingest($channel, ['message_id' => 2]);
        $this->assertContains('EMERGENCY_STOP', AgentDecision::latest('id')->first()->reasons);
        $this->quote(['bid' => 3368, 'ask' => 3368.3]);
        $trade = PaperTrade::where('mode', 'paper')->first();
        $this->assertSame('closed', $trade->status);
        $this->assertSame('STOP_LOSS', $trade->close_reason);
        $this->assertLessThan(3370, $trade->exit_price);
        $this->assertLessThan(0, $trade->realized_pnl);
    }

    public function test_price_retracement_retries_only_within_signal_window(): void
    {
        $channel = $this->channel();
        $this->quote(['bid' => 3380, 'ask' => 3380.3]);
        $this->ingest($channel);
        $this->assertSame('WAIT', AgentDecision::latest('id')->first()->action);
        $this->assertDatabaseCount('paper_trades', 0);
        $this->quote();
        $this->assertSame(1, PaperTrade::where('mode', 'paper')->count());
    }

    public function test_stale_and_future_quotes_and_old_signals_fail_closed(): void
    {
        $channel = $this->channel();
        $this->quote(['recorded_at' => now()->subMinutes(2)]);
        $this->ingest($channel);
        $this->assertContains('STALE_OR_FUTURE_QUOTE', AgentDecision::latest('id')->first()->reasons);
        $this->quote(['recorded_at' => now()->addMinute()]);
        $this->ingest($channel, ['message_id' => 2]);
        $this->assertContains('STALE_OR_FUTURE_QUOTE', AgentDecision::latest('id')->first()->reasons);
        $this->ingest($channel, ['message_id' => 3, 'posted_at' => now()->subMinutes(10)->toIso8601String()]);
        $this->assertContains('SIGNAL_EXPIRED_OR_FUTURE', AgentDecision::latest('id')->first()->reasons);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_same_symbol_from_another_channel_does_not_stack_exposure(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $second = $this->channel('second_research');
        $this->ingest($second);
        $this->assertSame(1, PaperTrade::where('mode', 'paper')->count());
        $this->assertContains('EXISTING_SYMBOL_EXPOSURE', AgentDecision::latest('id')->first()->reasons);
    }

    public function test_volume_is_rounded_down_and_total_cost_does_not_exceed_budget(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $trade = PaperTrade::where('mode', 'paper')->firstOrFail();
        $this->assertLessThanOrEqual(50, $trade->risk_amount);
        $this->assertEqualsWithDelta(round($trade->volume / 0.01), $trade->volume / 0.01, 1e-8);
        $this->assertEquals(-$trade->commission, $trade->realized_pnl);
        $signal = Signal::first();
        $decision = app(DecisionEngine::class)->evaluate($signal);
        $this->assertContains('DUPLICATE_TRADE', $decision->reasons);
    }

    public function test_partial_close_and_stop_tightening_require_an_explicit_reply(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $this->quote(['bid' => 3379, 'ask' => 3379.3]);
        $this->ingest($channel, ['message_id' => 2, 'text' => 'Close 50% and move SL to BE.']);
        $this->assertEquals(1, PaperTrade::first()->remaining_fraction);
        $this->ingest($channel, ['message_id' => 3, 'text' => 'Close 50% and move SL to BE.', 'reply_to_message_id' => 1]);
        $trade = PaperTrade::first();
        $this->assertEquals(0.5, $trade->remaining_fraction);
        $this->assertEquals($trade->entry_price, $trade->stop_loss);
        $this->ingest($channel, ['message_id' => 4, 'text' => 'Move SL to 3360', 'reply_to_message_id' => 1]);
        $this->assertEquals($trade->entry_price, $trade->fresh()->stop_loss);
        $this->assertDatabaseHas('trade_events', ['trade_id' => $trade->id, 'type' => 'UPDATE_REJECTED']);
    }

    public function test_historical_and_out_of_order_ticks_do_not_close_current_positions(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $this->quote(['bid' => 3300, 'ask' => 3300.3, 'is_historical' => true]);
        $this->quote(['bid' => 3300, 'ask' => 3300.3, 'recorded_at' => now()->subHour()]);
        $this->assertSame('open', PaperTrade::first()->status);
    }

    public function test_daily_loss_limit_uses_realized_and_floating_losses(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $trade = PaperTrade::where('mode', 'paper')->first();
        TradeEvent::create(['trade_id' => $trade->id, 'type' => 'PARTIAL_CLOSE', 'payload' => ['pnl' => -300], 'occurred_at' => now()]);
        $this->ingest($channel, ['message_id' => 2]);
        $this->assertContains('DAILY_LOSS_LIMIT', AgentDecision::latest('id')->first()->reasons);
    }

    public function test_future_timestamp_cannot_poison_current_portfolio_or_block_next_valid_tick(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $this->quote(['bid' => 3000, 'ask' => 3000.3, 'recorded_at' => now()->addHour()]);
        $this->assertGreaterThan(-100, app(Portfolio::class)->summary()['floating']);
        $this->quote(['bid' => 3386, 'ask' => 3386.3]);
        $this->assertSame('TAKE_PROFIT', PaperTrade::where('mode', 'paper')->first()->close_reason);
    }

    public function test_drawdown_uses_observed_floating_equity_high_water_mark(): void
    {
        $channel = $this->channel();
        $this->quote();
        $this->ingest($channel);
        $this->quote(['bid' => 3384, 'ask' => 3384.3]);
        $this->assertGreaterThan(10000, RiskProfile::current()->peak_equity);
        $this->quote();
        $this->assertGreaterThan(0.5, app(Portfolio::class)->summary()['drawdown_pct']);
    }

    public function test_backtest_never_uses_prices_before_signal_or_skips_unfinished_trade(): void
    {
        $channel = $this->channel();
        $this->quote(['recorded_at' => now()->subMinute(), 'is_historical' => true]);
        $this->ingest($channel, ['is_historical' => true]);
        $test = app(Backtester::class)->run($channel);
        $this->assertSame(0, $test->results['metrics']['sample_size']);
        $this->assertSame(1, $test->results['skipped']['no_entry']);
        $this->quote(['is_historical' => true]);
        $this->quote(['bid' => 3386, 'ask' => 3386.3, 'recorded_at' => now()->addMinute(), 'is_historical' => true]);
        $test = app(Backtester::class)->run($channel);
        $this->assertSame(1, $test->results['metrics']['sample_size']);
        $this->assertGreaterThan(0, $test->results['metrics']['expectancy_r']);
        $this->assertEquals(3385, $test->results['trades'][0]['exit']);
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_expectancy_and_profit_factor_include_losses(): void
    {
        $metrics = app(ChannelAnalytics::class)->metrics(collect([0.2, 0.2, 0.2, 0.2, -1]));
        $this->assertSame(80.0, $metrics['win_rate']);
        $this->assertLessThan(0, $metrics['expectancy_r']);
        $this->assertSame(0.8, $metrics['profit_factor']);
    }

    public function test_csv_import_rolls_back_entire_file_on_bad_row(): void
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->createWithContent('ticks.csv', "symbol,recorded_at,bid,ask\nXAUUSD,2026-01-01T00:00:00Z,3375,3375.3\nXAUUSD,invalid,0,-1\n");
        $this->actingAs($user)->post('/backtests/import', ['file' => $file])->assertSessionHasErrors();
        $this->assertDatabaseCount('market_snapshots', 0);
    }

    public function test_admin_pages_render_and_every_write_requires_login(): void
    {
        $this->post('/risk/emergency', ['enabled' => true])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $channel = $this->channel();
        $this->quote();
        $signal = $this->ingest($channel);
        foreach (['/', '/channels', '/channels/'.$channel->id, '/signals', '/signals/'.$signal->id, '/trades', '/trades?mode=shadow', '/agents', '/risk', '/lab', '/backtests', '/audit', '/integrations'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->post('/risk/emergency', ['enabled' => true])->assertRedirect();
        $this->assertTrue(RiskProfile::current()->emergency_stop);
        $this->assertDatabaseHas('audit_logs', ['action' => 'risk.emergency_stop']);
    }
}
