<?php

namespace App\Services\Funnel;

use App\Forecasting\ForecastEngine;
use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * The pre-launch funnel: Ads → Landing page → Email signup →
 * VIP upgrade → Kickstarter notify → Backer, with conversion at each step.
 */
class FunnelBuilder
{
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly ForecastEngine $forecasts,
    ) {}

    public function build(Project $project): array
    {
        $clicks = (int) $this->series->sum($project, 'clicks', self::WINDOW_DAYS, 'meta');
        $visitors = (int) $this->series->sum($project, 'sessions', self::WINDOW_DAYS);
        $subscribers = $project->subscribers()->count();
        $vips = $project->subscribers()->where('is_vip', true)->count();
        $notifyFollowers = (int) ($this->series->latest($project, 'ks_followers') ?? 0);
        $forecast = $this->forecasts->forProject($project, 0);

        $steps = [
            ['key' => 'ads', 'label' => 'Ads', 'value' => $clicks],
            ['key' => 'landing_page', 'label' => 'Landing page', 'value' => $visitors],
            ['key' => 'email_signup', 'label' => 'Email signup', 'value' => $subscribers],
            ['key' => 'vip_upgrade', 'label' => 'VIP upgrade', 'value' => $vips],
            ['key' => 'kickstarter_notify', 'label' => 'Kickstarter notify', 'value' => $notifyFollowers],
            ['key' => 'backer', 'label' => 'Backers (projected)', 'value' => $forecast->expectedBackers],
        ];

        foreach ($steps as $i => &$step) {
            $previous = $steps[$i - 1]['value'] ?? null;
            $step['conversion'] = ($previous !== null && $previous > 0)
                ? round($step['value'] / $previous * 100, 1)
                : null;
        }

        return $steps;
    }
}
