<?php

use App\Jobs\ImportLeadsForAllProjects;
use App\Jobs\SyncAllIntegrations;
use App\Jobs\SyncKickstarterFollowers;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pull fresh data from every connected integration each hour.
// Insight generation follows automatically via the IntegrationSynced event.
Schedule::job(new SyncAllIntegrations)->hourly();

// Instant Form leads sit inside Facebook until pulled out; fetch them and
// forward to the email provider so the list is usable at launch.
Schedule::job(new ImportLeadsForAllProjects)->hourly()->withoutOverlapping();

// Kickstarter publishes no API for follower counts, so the pre-launch page
// is read instead. These are the most valuable audience a project has.
Schedule::job(new SyncKickstarterFollowers)->hourly()->withoutOverlapping();
