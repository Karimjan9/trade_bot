<?php

namespace Tests\Feature;

use App\Models\MonitoringIssue;
use App\Models\MonitoringIssueEvent;
use App\Models\Signal;
use App\Models\TelegramChannel;
use App\Models\User;
use App\Services\Monitoring\Journal;
use App\Services\Monitoring\SystemIssues;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class MonitoringJournalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['trading.gateway_token' => str_repeat('g', 64), 'trading.arbitration.collection_seconds' => 0]);
    }

    private function channel(string $name = 'journal'): TelegramChannel
    {
        return app(ChannelRegistry::class)->add(['username' => $name, 'title' => $name, 'source' => 'telegram', 'telegram_id' => '-100'.random_int(100000, 999999)]);
    }

    private function record(string $source = 'one', array $evidence = []): MonitoringIssue
    {
        return app(Journal::class)->record('TEST_ERROR', 'scope', $source, ['kind' => 'ERROR', 'severity' => 'ERROR', 'title' => 'Test issue', 'description' => 'Observed a failure'], $evidence);
    }

    private function message(TelegramChannel $channel, int $id, string $text, array $raw = []): void
    {
        $event = app(EventIngestor::class)->ingest($channel, ['event_id' => (string) Str::uuid(), 'message_id' => $id, 'kind' => 'new', 'text' => $text, 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false, 'raw_json' => $raw]);
        app(MessageProcessor::class)->process($event->id);
        app(MessageProcessor::class)->process($event->id);
    }

    public function test_retries_do_not_duplicate_or_reopen_but_new_occurrences_reopen_with_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $issue = $this->record(evidence: ['count' => 1]);
        $this->record(evidence: ['count' => 1]);
        $this->assertSame(1, $issue->fresh()->occurrence_count);
        app(Journal::class)->changeStatus($issue, 'RESOLVED', $issue->status_version, 'Fixed the integration.', $user->id);
        $this->record();
        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->travel(1)->minute();
        $new = $this->record('two', ['count' => 2]);
        $this->assertSame($issue->id, $new->id);
        $this->assertSame('OPEN', $new->status);
        $this->assertSame(2, $new->occurrence_count);
        $this->assertNull($new->resolution);
        $this->assertSame(['count' => 1], $new->first_evidence);
        $this->assertSame(['count' => 2], $new->last_evidence);
        $this->assertDatabaseHas('monitoring_issue_events', ['action' => 'STATUS_CHANGED', 'body' => 'Fixed the integration.']);
        $this->assertDatabaseHas('monitoring_issue_events', ['action' => 'REOPENED']);
        $this->assertDatabaseCount('monitoring_issue_events', 3);
    }

    public function test_review_requires_explanation_and_stale_status_cannot_overwrite_new_finding(): void
    {
        $issue = $this->record();
        $this->get('/issues')->assertRedirect('/login');
        $this->get('/issues/'.$issue->id)->assertRedirect('/login');
        $this->post('/issues/'.$issue->id.'/comments', ['body' => 'No auth'])->assertRedirect('/login');
        $this->actingAs(User::factory()->create());
        $this->post('/issues/'.$issue->id.'/status', ['status' => 'RESOLVED', 'version' => $issue->status_version])->assertSessionHasErrors('note');
        $this->record('two');
        $this->post('/issues/'.$issue->id.'/status', ['status' => 'RESOLVED', 'version' => $issue->status_version, 'note' => 'Stale closure'])->assertStatus(409);
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->post('/issues/'.$issue->id.'/status', ['status' => 'WAITING_USER', 'version' => $issue->fresh()->status_version, 'note' => 'Birga ko‘ramiz'])->assertRedirect();
        $this->post('/issues/'.$issue->id.'/comments', ['body' => '<script>alert(1)</script>'])->assertRedirect();
        $this->get('/issues/'.$issue->id)->assertOk()->assertSee('Birga ko‘ramiz')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(1, MonitoringIssueEvent::where('action', 'COMMENT')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitoring.status_changed']);
    }

    public function test_manual_notes_filters_and_validation(): void
    {
        $channel = $this->channel();
        $other = $this->channel('other');
        $this->message($channel, 1, 'GOLD BUY 3299-3301 SL 3290 TP 3320');
        $signal = Signal::firstOrFail();
        $this->actingAs(User::factory()->create());
        $data = ['kind' => 'QUESTION', 'title' => 'Entry savoli', 'description' => 'Originalni tekshiramiz', 'signal_id' => $signal->id];
        $this->post('/issues', $data + ['channel_id' => $other->id])->assertSessionHasErrors('signal_id');
        $this->post('/issues', $data)->assertRedirect();
        $issue = MonitoringIssue::where('code', 'MANUAL_NOTE')->firstOrFail();
        $this->assertFalse($issue->automatic);
        $this->assertSame($channel->id, $issue->channel_id);
        $this->get('/issues?kind=QUESTION&q=Entry&channel_id='.$channel->id)->assertOk()->assertSee('Entry savoli');
        $this->get('/issues?kind=ERROR&q=Entry')->assertOk()->assertDontSee('Entry savoli');
        $this->post('/issues', $data + ['channel_id' => 99999])->assertSessionHasErrors('channel_id');
        $this->assertDatabaseCount('paper_trades', 0);
    }

    public function test_actionable_parser_and_linking_questions_are_grouped_without_video_or_cold_start_noise(): void
    {
        $channel = $this->channel();
        $this->message($channel, 1, 'SAVE');
        $this->message($channel, 2, 'SAVE');
        $save = MonitoringIssue::where('code', 'SAVE_FRACTION_UNKNOWN')->firstOrFail();
        $this->assertSame(2, $save->occurrence_count);
        $this->message($channel, 3, 'Close 50% and move SL to BE.');
        $this->message($channel, 4, '150 pips');
        $this->message($channel, 5, '', ['_signal_forge_media' => ['kind' => 'video']]);
        $this->message($channel, 6, 'Good morning everyone');
        $this->message($channel, 7, 'GOLD BUY 3299-3301');
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'UNLINKED_UPDATE']);
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'UNLINKED_RESULT']);
        $this->assertDatabaseCount('monitoring_issues', 3);
        $this->assertSame(1, Signal::count());
        $this->assertDatabaseCount('paper_trades', 0);
        $this->message($channel, 8, '', ['_signal_forge_media' => ['kind' => 'photo']]);
        $this->assertDatabaseHas('monitoring_issues', ['code' => 'IMAGE_READER_UNAVAILABLE']);
        $this->assertDatabaseCount('monitoring_issues', 4);
    }

    public function test_failed_queue_event_and_collector_share_one_safe_occurrence(): void
    {
        $uuid = (string) Str::uuid();
        $class = 'App\\Jobs\\ProcessTelegramEvent';
        $job = Mockery::mock(DatabaseJob::class);
        $job->shouldReceive('uuid')->andReturn($uuid);
        $job->shouldReceive('payload')->andReturn(['displayName' => $class, 'data' => ['command' => 'token=do-not-copy']]);
        Event::dispatch(new JobFailed('database', $job, new RuntimeException('secret=do-not-copy')));
        DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => json_encode(['displayName' => $class, 'data' => ['command' => 'token=do-not-copy']]), 'exception' => 'RuntimeException: secret=do-not-copy in private-file:5', 'failed_at' => now()]);
        $this->artisan('platform:collect-issues')->assertSuccessful();
        $this->artisan('platform:collect-issues')->assertSuccessful();
        $issue = MonitoringIssue::firstOrFail();
        $this->assertSame(1, $issue->occurrence_count);
        $this->assertSame($class, $issue->last_evidence['job_class']);
        $this->assertSame('RuntimeException', $issue->last_evidence['exception_class']);
        $this->assertStringNotContainsString('do-not-copy', $issue->toJson().MonitoringIssueEvent::all()->toJson());
        $this->assertDatabaseCount('monitoring_issues', 1);
    }

    public function test_gateway_incidents_repeat_once_until_recovered_and_reopen_on_new_outage(): void
    {
        $channel = $this->channel();
        $this->withToken(config('trading.gateway_token'));
        $this->postJson('/api/gateway/heartbeat', ['status' => 'ONLINE'])->assertOk();
        $this->assertDatabaseCount('monitoring_issues', 0);
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/gateway/heartbeat', ['status' => 'DEGRADED', 'metadata' => ['token' => 'do-not-copy']])->assertOk();
            $this->patchJson('/api/gateway/channels/'.$channel->id, ['status' => 'ERROR', 'telegram_id' => $channel->telegram_id, 'last_error' => 'secret=do-not-copy'])->assertOk();
        }
        app(SystemIssues::class)->collect();
        $this->assertSame([1, 1], MonitoringIssue::orderBy('id')->pluck('occurrence_count')->all());
        $this->assertStringNotContainsString('do-not-copy', MonitoringIssue::all()->toJson().MonitoringIssueEvent::all()->toJson());
        $issue = MonitoringIssue::where('code', 'SERVICE_DEGRADED')->firstOrFail();
        $user = User::factory()->create();
        app(Journal::class)->changeStatus($issue, 'RESOLVED', $issue->status_version, 'Service recovered', $user->id);
        $this->postJson('/api/gateway/heartbeat', ['status' => 'DEGRADED'])->assertOk();
        $this->assertSame('RESOLVED', $issue->fresh()->status);
        $this->postJson('/api/gateway/heartbeat', ['status' => 'ONLINE'])->assertOk();
        $this->postJson('/api/gateway/heartbeat', ['status' => 'DEGRADED'])->assertOk();
        $this->assertSame('OPEN', $issue->fresh()->status);
        $this->assertSame(2, $issue->fresh()->occurrence_count);
    }

    public function test_automatic_evidence_redacts_secrets_and_bounds_nested_data(): void
    {
        $issue = $this->record(evidence: ['token' => 'private', 'nested' => ['api_hash' => 'private', 'header' => 'Bearer private', 'detail' => 'password=private'], 'raw_json' => ['session' => 'private'], 'long' => str_repeat('x', 10000)]);
        $encoded = $issue->toJson().MonitoringIssueEvent::all()->toJson();
        $this->assertStringNotContainsString('private', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
        $this->assertSame(600, mb_strlen($issue->last_evidence['long']));
    }

    public function test_reported_application_error_is_journaled_without_request_or_exception_text(): void
    {
        $this->get('/missing-page')->assertNotFound();
        $this->assertDatabaseCount('monitoring_issues', 0);
        report(new RuntimeException('token=do-not-copy'));
        $issue = MonitoringIssue::where('code', 'APPLICATION_ERROR')->firstOrFail();
        $this->assertSame('RuntimeException', $issue->last_evidence['exception_class']);
        $this->assertStringNotContainsString('do-not-copy', $issue->toJson().MonitoringIssueEvent::all()->toJson());
    }
}
