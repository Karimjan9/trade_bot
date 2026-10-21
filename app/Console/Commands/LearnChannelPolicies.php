<?php

namespace App\Console\Commands;

use App\Models\TelegramChannel;
use App\Services\Analytics\ChannelPolicyLearner;
use App\Services\Analytics\ChannelStrategyLearner;
use App\Services\Analytics\ClaimVerifier;
use Illuminate\Console\Command;

class LearnChannelPolicies extends Command
{
    protected $signature = 'platform:research-channels {channel? : Optional local channel ID} {--claims-only}';

    protected $description = 'Learn channel-specific paper management policies from covered historical prices and audit result claims';

    public function handle(ChannelPolicyLearner $learner, ClaimVerifier $verifier): int
    {
        $query = TelegramChannel::where('enabled', true);
        if ($this->argument('channel')) {
            $query->whereKey($this->argument('channel'));
        }
        foreach ($query->lazyById(100) as $channel) {
            if (! $this->option('claims-only')) {
                foreach (array_keys(config('trading.symbols')) as $symbol) {
                    foreach (['BUY', 'SELL'] as $direction) {
                        $policy = $learner->fit($channel, $symbol, $direction);
                        if ($policy) {
                            $this->line($channel->id.' '.$symbol.' '.$direction.' policy #'.$policy->id.' '.$policy->status);
                        }
                        $strategy = app(ChannelStrategyLearner::class)->fit($channel, $symbol, $direction);
                        $this->line($channel->id.' '.$symbol.' '.$direction.' strategy #'.$strategy->id.' '.$strategy->status);
                    }
                }
            }
            $this->line($verifier->channel($channel).' result reports examined for channel '.$channel->id);
        }

        return self::SUCCESS;
    }
}
