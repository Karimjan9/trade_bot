<?php

namespace App\Providers;

use App\Services\Monitoring\SystemIssues;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::failing(function (JobFailed $event) {
            app(SystemIssues::class)->jobFailure($event->job->uuid() ?? (string) $event->job->getJobId(), $event->job->payload()['displayName'] ?? 'UnknownJob', $event->exception::class);
        });
    }
}
