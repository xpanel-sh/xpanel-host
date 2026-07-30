<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('xpanel:backups-run')->hourly()->withoutOverlapping();
Schedule::command('xpanel:ssl-sync')->daily()->withoutOverlapping();
