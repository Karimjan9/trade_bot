<?php

namespace App\Console\Commands;

use App\Services\Monitoring\SystemIssues;
use Illuminate\Console\Command;

class CollectMonitoringIssues extends Command
{
    protected $signature = 'platform:collect-issues';

    protected $description = 'Collect durable failed jobs and reported integration errors into the monitoring journal';

    public function handle(SystemIssues $issues): int
    {
        $issues->collect();
        $this->info('Monitoring journal synchronized.');

        return self::SUCCESS;
    }
}
