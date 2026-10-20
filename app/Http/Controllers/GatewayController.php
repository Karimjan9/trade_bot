<?php

namespace App\Http\Controllers;

use App\Models\ServiceHeartbeat;
use App\Models\TelegramChannel;
use App\Services\Signals\EventIngestor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GatewayController extends Controller
{
    public function channels()
    {
        return TelegramChannel::where('enabled', true)->where('source', 'telegram')->get(['id', 'username', 'telegram_id', 'status', 'last_message_id']);
    }

    public function updateChannel(Request $request, TelegramChannel $channel)
    {
        abort_unless($channel->enabled && $channel->source === 'telegram', 403);
        $data = $request->validate(['telegram_id' => ['required', 'string', 'regex:/^-100\d+$/', Rule::unique('telegram_channels')->ignore($channel)], 'status' => ['required', Rule::in(['IMPORTING', 'ANALYZING', 'PAPER', 'ERROR'])], 'last_error' => 'nullable|string|max:1000']);
        abort_if($channel->telegram_id && $channel->telegram_id !== $data['telegram_id'], 409, 'Channel identity changed.');
        $channel->update($data);

        return response()->json(['ok' => true]);
    }

    public function event(Request $request, EventIngestor $ingestor)
    {
        $data = $request->validate([
            'event_id' => 'required|uuid', 'channel_id' => 'required|integer|exists:telegram_channels,id', 'telegram_id' => 'required|string',
            'message_id' => 'required|integer|min:1', 'kind' => ['required', Rule::in(['new', 'edit', 'delete'])],
            'text' => 'present|nullable|string|max:50000', 'raw_json' => 'sometimes|array', 'reply_to_message_id' => 'nullable|integer|min:1',
            'posted_at' => 'required|date', 'occurred_at' => 'required|date', 'is_historical' => 'required|boolean',
        ]);
        $channel = TelegramChannel::findOrFail($data['channel_id']);
        abort_unless($channel->enabled && $channel->source === 'telegram' && $channel->telegram_id && $channel->telegram_id === $data['telegram_id'], 403, 'Channel is not whitelisted.');
        $event = $ingestor->ingest($channel, $data);

        return response()->json(['id' => $event->event_id, 'status' => $event->status], $event->wasRecentlyCreated ? 202 : 200);
    }

    public function heartbeat(Request $request)
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ONLINE', 'DEGRADED'])], 'metadata' => 'sometimes|array']);
        ServiceHeartbeat::updateOrCreate(['service' => 'telegram'], ['status' => $data['status'], 'metadata' => $data['metadata'] ?? [], 'seen_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
