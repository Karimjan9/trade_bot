<?php

namespace App\Services\Monitoring;

use App\Models\AuditLog;
use App\Models\MonitoringIssue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Journal
{
    /** A source identifies an actual occurrence, not a retry or a recheck. */
    public function record(string $code, string $scope, string $source, array $attributes, array $evidence = [], ?int $userId = null): MonitoringIssue
    {
        $safe = $this->sanitize(array_merge($evidence, array_intersect_key($attributes, array_flip(['channel_id', 'signal_id', 'message_id']))));

        return DB::transaction(function () use ($code, $scope, $source, $attributes, $safe, $userId) {
            $issue = MonitoringIssue::firstOrCreate(['fingerprint' => hash('sha256', $code.'|'.$scope)], array_merge(array_intersect_key($attributes, array_flip(['kind', 'severity', 'title', 'description', 'question', 'channel_id', 'signal_id', 'message_id', 'automatic'])), [
                'code' => $code, 'status' => 'OPEN', 'first_evidence' => $safe, 'last_evidence' => $safe, 'first_seen_at' => now(), 'last_seen_at' => now(),
            ]));
            $issue = MonitoringIssue::lockForUpdate()->findOrFail($issue->id);
            $reopen = in_array($issue->status, MonitoringIssue::CLOSED);
            $event = $issue->events()->firstOrCreate(['event_key' => hash('sha256', $source)], [
                'user_id' => $userId, 'action' => $reopen ? 'REOPENED' : 'OCCURRENCE', 'evidence' => $safe, 'created_at' => now(),
            ]);
            if (! $event->wasRecentlyCreated) {
                return $issue;
            }
            $issue->fill(['last_evidence' => $safe, 'last_seen_at' => now(), 'occurrence_count' => $issue->occurrence_count + 1, 'status_version' => $issue->status_version + 1]);
            if ($reopen) {
                $issue->fill(['status' => 'OPEN', 'resolution' => null, 'resolved_at' => null]);
            }
            $issue->save();

            return $issue;
        }, 3);
    }

    public function changeStatus(MonitoringIssue $issue, string $status, int $version, ?string $note, int $userId): void
    {
        DB::transaction(function () use ($issue, $status, $version, $note, $userId) {
            $locked = MonitoringIssue::lockForUpdate()->findOrFail($issue->id);
            abort_unless($locked->status_version === $version, 409, 'Yozuv yangilangan. Sahifani yangilang va qayta ko‘ring.');
            if (! array_key_exists($status, MonitoringIssue::STATUSES) || (in_array($status, MonitoringIssue::CLOSED) && trim($note ?? '') === '')) {
                throw ValidationException::withMessages(['note' => 'Yopishda yechim yoki sababni yozing.']);
            }
            $before = $locked->status;
            $closed = in_array($status, MonitoringIssue::CLOSED);
            $locked->update(['status' => $status, 'status_version' => $version + 1, 'resolution' => $closed ? $note : null, 'resolved_at' => $closed ? now() : null]);
            $locked->events()->create(['user_id' => $userId, 'action' => 'STATUS_CHANGED', 'body' => $note, 'evidence' => ['from' => $before, 'to' => $status], 'created_at' => now()]);
            AuditLog::record('monitoring.status_changed', 'issue:'.$locked->id, ['status' => $before], ['status' => $status]);
        }, 3);
    }

    public function comment(MonitoringIssue $issue, string $body, int $userId): void
    {
        $issue->events()->create(['user_id' => $userId, 'action' => 'COMMENT', 'body' => $body, 'created_at' => now()]);
    }

    /** Automatic evidence is deliberately small; callers must not pass raw payloads/logs. */
    public function sanitize(array $value, int $depth = 0): array
    {
        if ($depth > 4) {
            return ['truncated' => true];
        }
        $result = [];
        foreach (array_slice($value, 0, 30, true) as $key => $item) {
            if (preg_match('/password|token|secret|api_hash|authorization|cookie|session_key|raw_text|raw_json|payload|trace|stderr|stdout/i', (string) $key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($item)) {
                $result[$key] = $this->sanitize($item, $depth + 1);
            } elseif (is_string($item)) {
                $item = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $item);
                $item = preg_replace('/\b(password|token|secret|api_hash)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $item);
                $result[$key] = mb_substr($item, 0, 600);
            } elseif (is_scalar($item) || $item === null) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
