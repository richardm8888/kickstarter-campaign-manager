<?php

namespace App\Services\Dashboard;

use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * The opinionated path from "just signed up" to "campaign running", shown
 * on the dashboard until every step is done.
 *
 * A new project is otherwise a wall of zeros: eight empty cards and a flat
 * funnel, with nothing saying what to do first. This is that answer, in
 * the order that experience says pays off — date and page before ads,
 * because ads without a destination buy nothing.
 */
class SetupChecklist
{
    public function __construct(private readonly MetricSeries $series) {}

    /** @return array{complete: bool, steps: list<array<string, mixed>>} */
    public function build(Project $project): array
    {
        $connected = Integration::query()
            ->where('project_id', $project->id)
            ->where('status', Integration::STATUS_CONNECTED)
            ->pluck('provider')
            ->all();

        $landingPage = $project->landingPage;

        $steps = [
            [
                'key' => 'launch_date',
                'label' => 'Set your launch date',
                'detail' => 'Everything here paces itself around it — budget per day, the final-week push, the countdown.',
                'done' => $project->launch_date !== null,
                'route' => 'settings',
            ],
            [
                'key' => 'landing_page',
                'label' => 'Publish your landing page',
                'detail' => 'Ads need somewhere to send people. Use the built-in page, or link your own and we score it instead.',
                'done' => ($landingPage?->published ?? false) || $project->external_landing_url !== null,
                'route' => 'landing-page',
            ],
            [
                'key' => 'kickstarter_url',
                'label' => 'Link your Kickstarter pre-launch page',
                'detail' => 'We read your follower count from it hourly. Followers back at ~20%, ten times a plain email address.',
                'done' => $project->kickstarter_url !== null,
                'route' => 'settings',
            ],
            [
                'key' => 'meta',
                'label' => 'Connect Meta Ads',
                'detail' => 'Every recommendation on the Ads and Forecast pages is built from your own campaign data.',
                'done' => in_array('meta', $connected, true),
                'route' => 'integrations',
            ],
            [
                'key' => 'mailerlite',
                'label' => 'Connect MailerLite',
                'detail' => 'Form leads are pulled out of Meta and pushed to your list every hour — but only once this is connected.',
                'done' => in_array('mailerlite', $connected, true),
                'route' => 'integrations',
            ],
            [
                'key' => 'first_ad',
                'label' => 'Run the three ads',
                'detail' => 'Instant form, landing page, Kickstarter page. A small test budget replaces every benchmark with your numbers.',
                'done' => $this->series->sum($project, 'spend', 30, 'meta') > 0,
                'route' => 'ads',
            ],
        ];

        return [
            'complete' => ! in_array(false, array_column($steps, 'done'), true),
            'steps' => $steps,
        ];
    }
}
