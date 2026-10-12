<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('staff:reconcile-roles')->daily();
Schedule::command('posts:publish-scheduled')->everyMinute();
Schedule::command('newsroom:purge-security-data')->dailyAt('03:15');
Schedule::command('auth:clear-resets')->dailyAt('03:20');
