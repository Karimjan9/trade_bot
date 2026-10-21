<?php

namespace App\Services\Analytics;

use App\Models\ChannelPolicy;
use App\Models\TelegramChannel;
use App\Models\TelegramMessageEvent;
use App\Services\Signals\MediaPolicy;
use App\Services\Signals\SignalParser;
use Illuminate\Support\Facades\DB;

class ChannelEvidence
{
    public function inspect(TelegramChannel $channel): array
    {
        $counts = ['observed_messages' => 0, 'complete_signals' => 0, 'incomplete_signals' => 0, 'edited_signal_messages' => 0, 'deleted_signal_messages' => 0, 'result_reports' => 0, 'save_needs_policy' => 0, 'unlinked_updates' => 0, 'video_posts_skipped' => 0];
        $reports = [];
        $checks = DB::table('claim_checks as c')->join('signal_events as s', 's.id', '=', 'c.signal_event_id')->join('telegram_message_events as e', 'e.id', '=', 's.source_event_id')->where('e.channel_id', $channel->id)->select('c.*', 'e.telegram_message_id')->orderByDesc('c.id')->get()->unique(fn ($row) => $row->signal_event_id.':'.$row->claim_index)->groupBy('telegram_message_id');
        $incomplete = [];
        // Analyze first-observed text. Realtime/history redeliveries and successive edits
        // must not inflate counts. This projection never changes trades or validates claims.
        foreach ($channel->messages()->with('signal')->lazyById(200) as $message) {
            $counts['observed_messages']++;
            $parsed = app(MediaPolicy::class)->parse($message->raw_text, $message->raw_json ?? []);
            if (app(MediaPolicy::class)->isVideo($message->raw_json ?? [])) {
                $counts['video_posts_skipped']++;
            }
            $isIncomplete = ($parsed['reason_code'] ?? null) === 'INCOMPLETE_SIGNAL';
            $signalLike = $message->signal !== null || $parsed['type'] === 'NEW_SIGNAL' || $isIncomplete;
            if ($isIncomplete) {
                $counts['incomplete_signals']++;
                $incomplete[] = ['message_id' => $message->telegram_message_id, 'candidate' => $parsed['candidate'], 'missing_fields' => $parsed['missing_fields'], 'deleted' => $message->deleted_at !== null];
                if (count($incomplete) > 20) {
                    array_shift($incomplete);
                }
            } elseif ($signalLike) {
                $counts['complete_signals']++;
            }
            if ($signalLike && $message->edited_at) {
                $counts['edited_signal_messages']++;
            }
            if ($signalLike && $message->deleted_at) {
                $counts['deleted_signal_messages']++;
            }
            if (($parsed['intent'] ?? null) === 'SAVE_POSITION' && $parsed['type'] === 'REVIEW_REQUIRED') {
                $counts['save_needs_policy']++;
            }
            if ($message->processing_status === 'unlinked_update') {
                $counts['unlinked_updates']++;
            }
            if ($parsed['type'] === 'RESULT_REPORT') {
                $counts['result_reports']++;
                $reports[] = ['message_id' => $message->telegram_message_id, 'reply_to' => $message->reply_to_message_id, 'text' => $message->raw_text, 'claims' => $parsed['claims'], 'verification' => 'UNVERIFIED', 'checks' => $checks->get($message->telegram_message_id, collect())];
                if (count($reports) > 20) {
                    array_shift($reports);
                }
            }
        }
        $revisions = TelegramMessageEvent::where('channel_id', $channel->id)->whereIn('kind', ['edit', 'delete'])->where('status', 'processed')->latest('id')->limit(20)->get();

        return ['counts' => $counts, 'reports' => $reports, 'incomplete' => $incomplete, 'revisions' => $revisions, 'parser_version' => SignalParser::VERSION, 'policies' => ChannelPolicy::where('channel_id', $channel->id)->latest('id')->limit(10)->get()];
    }
}
