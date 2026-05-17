<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('fishbook:sentry-smoke', function () {
    if (app()->environment('production')) {
        $this->error('Refusing to run in production.');

        return 1;
    }

    throw new RuntimeException('Sentry smoke (intentional)');
})->purpose('Throw an exception to validate Sentry wiring; non-production only.');

Schedule::command('backgrounds:purge-orphans')->dailyAt('03:00');
