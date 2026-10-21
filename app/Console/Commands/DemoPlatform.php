<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Signals\ChannelRegistry;
use App\Services\Signals\EventIngestor;
use App\Services\Signals\MessageProcessor;
use App\Services\Trading\DecisionEngine;
use App\Services\Trading\MarketData;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DemoPlatform extends Command
{
    protected $signature = 'platform:demo';

    protected $description = 'Run one explicitly labeled synthetic paper/shadow scenario';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo is restricted to local/testing.');

            return self::FAILURE;
        }
        $channel = TelegramChannel::where('username', 'demo_research')->first() ?? app(ChannelRegistry::class)->add(['username' => 'demo_research', 'title' => 'Demo Research · synthetic', 'source' => 'manual']);
        $quote = ['symbol' => 'XAUUSD', 'bid' => 3376, 'ask' => 3376.3, 'regime' => 'TREND_UP', 'session' => 'LONDON', 'source' => 'synthetic_demo', 'market_open' => true, 'is_historical' => false, 'recorded_at' => now()];
        app(MarketData::class)->record($quote);
        $event = app(EventIngestor::class)->ingest($channel, ['event_id' => (string) Str::uuid(), 'message_id' => $channel->last_message_id + 1, 'kind' => 'new', 'text' => "GOLD BUY NOW\n3375-3378\nSL 3370\nTP1 3385\nTP2 3392", 'posted_at' => now()->toIso8601String(), 'occurred_at' => now()->toIso8601String(), 'is_historical' => false, 'raw_json' => ['source' => 'synthetic_demo']]);
        app(MessageProcessor::class)->process($event->id);
        sleep((int) config('trading.arbitration.collection_seconds'));
        app(DecisionEngine::class)->evaluate($event->channel->signals()->latest('id')->firstOrFail());
        $quote['bid'] = 3385;
        $quote['ask'] = 3385.3;
        $quote['recorded_at'] = now();
        app(MarketData::class)->record($quote);
        $this->info('Synthetic scenario completed. See Dashboard, Signals and Paper/Shadow trades.');

        return self::SUCCESS;
    }
}
