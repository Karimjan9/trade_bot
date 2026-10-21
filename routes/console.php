<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('platform:recover-events')->everyMinute()->withoutOverlapping();
Schedule::command('platform:collect-issues')->everyMinute()->withoutOverlapping();
Schedule::command('platform:research-channels')->hourly()->withoutOverlapping();
Schedule::command('platform:research-channels --claims-only')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('platform:learn-models')->everyFiveMinutes()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
