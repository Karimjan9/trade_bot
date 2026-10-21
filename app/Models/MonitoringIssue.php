<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringIssue extends Model
{
    public const STATUSES = ['OPEN' => 'Ochiq', 'IN_PROGRESS' => 'Ishlanmoqda', 'WAITING_USER' => 'Birga aniqlashtiramiz', 'RESOLVED' => 'Hal qilindi', 'DISMISSED' => 'Muammo emas'];

    public const KINDS = ['QUESTION' => 'Savol', 'ERROR' => 'Xato', 'ANOMALY' => 'Tafovut'];

    public const CLOSED = ['RESOLVED', 'DISMISSED'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['automatic' => 'boolean', 'status_version' => 'integer', 'occurrence_count' => 'integer', 'first_evidence' => 'array', 'last_evidence' => 'array', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function events()
    {
        return $this->hasMany(MonitoringIssueEvent::class, 'issue_id');
    }

    public function channel()
    {
        return $this->belongsTo(TelegramChannel::class, 'channel_id');
    }

    public function signal()
    {
        return $this->belongsTo(Signal::class, 'signal_id');
    }

    public function message()
    {
        return $this->belongsTo(TelegramMessage::class, 'message_id');
    }
}
