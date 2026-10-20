<?php

namespace App\Services\Signals;

use App\Models\AuditLog;
use App\Models\TelegramChannel;
use App\Services\Analytics\ChannelAnalytics;
use Illuminate\Support\Facades\DB;

class ChannelRegistry
{
    public function add(array $data): TelegramChannel
    {
        return DB::transaction(function () use ($data) {
            if (($data['source'] ?? 'telegram') === 'manual') {
                $data['status'] = 'PAPER';
            }
            $channel = TelegramChannel::create($data);
            $channel->agents()->create(['name' => $channel->title.' · TP1', 'role' => 'champion', 'state' => 'PAPER', 'parameters' => ['exit' => 'first_tp', 'min_score' => 65]]);
            $channel->agents()->create(['name' => $channel->title.' · Final TP', 'role' => 'challenger', 'state' => 'SHADOW', 'parameters' => ['exit' => 'last_tp']]);
            app(ChannelAnalytics::class)->refresh($channel);
            AuditLog::record('channel.created', 'channel:'.$channel->id, null, $channel->toArray());

            return $channel;
        });
    }
}
