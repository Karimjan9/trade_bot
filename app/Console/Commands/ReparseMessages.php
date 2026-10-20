<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\TelegramMessage;
use App\Models\TelegramMessageEvent;
use App\Services\Signals\SignalParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReparseMessages extends Command
{
    protected $signature = 'platform:reparse {channel : Local channel ID}';

    protected $description = 'Store missing parser-version analyses of archived events without trading or changing original signals';

    public function handle(SignalParser $parser): int
    {
        $count = 0;
        TelegramMessageEvent::where('channel_id', $this->argument('channel'))->whereIn('kind', ['new', 'edit'])->orderBy('id')->chunkById(200, function ($events) use ($parser, &$count) {
            foreach ($events as $event) {
                $message = TelegramMessage::where('channel_id', $event->channel_id)->where('telegram_message_id', $event->telegram_message_id)->first();
                if (! $message) {
                    continue;
                }
                $count += DB::table('signal_parses')->insertOrIgnore(['message_id' => $message->id, 'source_event_id' => $event->id, 'parser_version' => SignalParser::VERSION, 'result' => json_encode($parser->parse($event->payload['text'] ?? ''), JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        AuditLog::record('parser.reparse', 'channel:'.$this->argument('channel'), null, ['version' => SignalParser::VERSION, 'new_analyses' => $count]);
        $this->info($count.' parser analyses saved. Existing signals and trades unchanged.');

        return self::SUCCESS;
    }
}
