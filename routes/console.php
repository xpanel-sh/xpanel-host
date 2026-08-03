<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('xpanel:backups-run')->hourly()->withoutOverlapping();
Schedule::command('xpanel:ssl-sync')->daily()->withoutOverlapping();
Schedule::command('xpanel:resources-collect')->everyFiveMinutes()->withoutOverlapping();
Schedule::call(fn () => DB::table('notifications')
    ->whereNotNull('read_at')
    ->where('created_at', '<', now()->subDays(90))
    ->delete())
    ->daily()
    ->name('xpanel:notifications-prune')
    ->withoutOverlapping();
