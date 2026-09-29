<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('core:heartbeat', function () {
    $this->info('Core scheduler is running.');
})->purpose('Verify the Core scheduler can run');

Schedule::command('core:heartbeat')->everyMinute();
Schedule::command('sanctum:prune-expired --hours=24')->daily()->withoutOverlapping();
Schedule::command('telescope:prune --hours=48')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('realtime:prune-events')->dailyAt('02:15')->withoutOverlapping();
Schedule::command('core:prune-failed-jobs')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('data-transfers:prune')->dailyAt('02:45')->withoutOverlapping();

Schedule::command('billing:notify-due')->dailyAt('07:00')->withoutOverlapping();
