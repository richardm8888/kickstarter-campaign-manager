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

        $forecast = $this->forecasts->forProject($project);

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
                'change' => $this->series->changePercent($project, 'signup_rate'),
            ],
            'cac' => [
                // Cost, in minor units, to acquire one subscriber this window.
                'value' => $newSubscribers > 0 ? (int) round($spend * 100 / $newSubscribers) : null,
                'change' => null,
            ],
            'revenue' => [
                // Stripe revenue (VIP upgrades) in minor units.
                'value' => (int) round($this->series->sum($project, 'revenue', self::WINDOW_DAYS, 'stripe') * 100),
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
