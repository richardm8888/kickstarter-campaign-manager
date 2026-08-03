<?php

namespace App\Services\Dashboard;

use App\Forecasting\ForecastEngine;
use App\Models\Project;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;

class DashboardMetrics
{
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly ForecastEngine $forecasts,
        private readonly AudienceSize $audience,
    ) {}

    public function cards(Project $project): array
    {
        $visitors = $this->series->sum($project, 'sessions', self::WINDOW_DAYS);
        $subscribers = $this->audience->total($project);
        $vips = $this->audience->vips($project);
        $spend = $this->series->sum($project, 'spend', self::WINDOW_DAYS, 'meta');

        $newSubscribers = $this->audience->recentSignups($project, self::WINDOW_DAYS);

        // Spend 0: the dashboard reports what the audience is worth today.
        // Hypothetical budgets live on the Forecast page — projecting a
        // default spend here showed backers a brand-new project cannot have.
        $forecast = $this->forecasts->forProject($project, 0);

        return [
            'visitors' => [
                'value' => (int) $visitors,
                'change' => $this->series->changePercent($project, 'sessions'),
            ],
            'email_subscribers' => [
                'value' => $subscribers,
                'change' => null,
            ],
            'vip_upgrades' => [
                'value' => $vips,
                'change' => null,
            ],
            'conversion_rate' => [
                'value' => $visitors > 0 ? round($newSubscribers / $visitors * 100, 1) : null,
                'change' => null,
            ],
            'cac' => [
                // Cost, in minor units, to acquire one subscriber this window.
                'value' => $newSubscribers > 0 ? (int) round($spend * 100 / $newSubscribers) : null,
                'change' => null,
            ],
            // The audience Kickstarter notifies at launch — worth ten email
            // subscribers a head, so it earns a card. Revenue stays on the
            // Analytics page; pre-launch it is pennies of VIP upgrades.
            'ks_followers' => [
                'value' => $this->audience->followers($project),
                'change' => null,
            ],
            'projected_backers' => [
                'value' => $forecast->expectedBackers,
                'change' => null,
            ],
            'funding_forecast' => [
                'value' => $forecast->expectedFunding,
                'goal' => $forecast->fundingGoal,
                'coverage' => $forecast->goalCoverage,
                'confidence' => $forecast->confidence,
            ],
        ];
    }
}
