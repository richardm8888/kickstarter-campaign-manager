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
//
// Two cadences, and they must not share a mutex. Schedule::job() names an
// event after its job class, so both of these would otherwise lock each
// other out — and since the quick poll is registered first, it would win
// every hour and the wide sweep would never run at all.

// Someone who just handed over their email should get the welcome message
// while they still remember doing so. One day of lookback keeps the API
// cost of polling this often proportionate: the answer is almost always
// "nothing new".
Schedule::job(new ImportLeadsForAllProjects(days: 1))
    ->name('import-leads-poll')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

// The wide sweep is the safety net: it re-checks a month and re-forwards
// anyone the email provider never accepted, so a lead that arrived during
// an expired token or a MailerLite outage is not lost for good.
Schedule::job(new ImportLeadsForAllProjects(days: 30))
    ->name('import-leads-sweep')
    ->hourly()
    ->withoutOverlapping(30);

// Kickstarter publishes no API for follower counts, so the pre-launch page
// is read instead. These are the most valuable audience a project has.
// The lock expires well inside the hour: Laravel would otherwise hold it
// for a day if a run died, and follower counts would silently stop.
Schedule::job(new SyncKickstarterFollowers)->hourly()->withoutOverlapping(30);
