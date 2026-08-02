<?php

use App\Jobs\SyncAllIntegrations;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull fresh data from every connected integration each hour.
// Insight generation follows automatically via the IntegrationSynced event.
Schedule::job(new SyncAllIntegrations)->hourly();
