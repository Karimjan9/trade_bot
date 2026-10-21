<?php

namespace App\Console\Commands;

use App\Jobs\LearnChannelModel;
use App\Models\TelegramChannel;
use Illuminate\Console\Command;

class LearnSignalModels extends Command
{
    protected $signature = 'platform:learn-models {channel?} {--sync : Run locally instead of queueing}';

    protected $description = 'Label all signal outcomes and train validated per-channel ML challengers';

    public function handle(): int
    {
        $query = TelegramChannel::where('enabled', true);
        if ($this->argument('channel')) {
            $query->whereKey($this->argument('channel'));
        }
        foreach ($query->lazyById() as $channel) {
            if ($this->option('sync')) {
                (new LearnChannelModel($channel->id))->handle();
            } else {
                LearnChannelModel::dispatch($channel->id);
            }
            $this->line('Channel '.$channel->id.($this->option('sync') ? ' examined' : ' queued'));
        }

        return self::SUCCESS;
    }
}
