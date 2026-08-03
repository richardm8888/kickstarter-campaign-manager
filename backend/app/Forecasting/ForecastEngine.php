<?php

namespace App\Forecasting;

use App\Models\Project;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;

/**
 * Deterministic pre-launch funding forecast.
 *
 * Model: planned ad spend buys visitors at the observed (or benchmark) CPC;
 * visitors convert to email subscribers; subscribers and £1 VIPs convert to
 * backers at different rates; backers pledge the project's average pledge.
 * No randomness — the same input always produces the same forecast.
 */
class ForecastEngine
{
    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
    ) {}

    public function forecast(ForecastInput $input): Forecast
    {
        $projectedVisitors = $input->cpc > 0
            ? (int) floor($input->plannedAdSpend / 100 / $input->cpc)
            : 0;

        $newSubscribers = (int) floor($projectedVisitors * $input->visitorToSubscriberRate);
        $projectedSubscribers = $input->emailSubscribers + $newSubscribers;

        // VIPs grow proportionally with the list when there is an observed VIP share.
        $vipShare = $input->emailSubscribers > 0
            ? $input->vipCount / $input->emailSubscribers
            : 0.0;
        $projectedVips = $input->vipCount + (int) floor($newSubscribers * $vipShare);

        $standardSubscribers = max(0, $projectedSubscribers - $projectedVips);
        $expectedBackers = (int) floor(
            $standardSubscribers * $input->subscriberToBackerRate
            + $projectedVips * $input->vipToBackerRate
        );

        $expectedFunding = $expectedBackers * $input->averagePledge;

        $goalCoverage = $input->fundingGoal > 0
            ? round($expectedFunding / $input->fundingGoal, 3)
            : 0.0;

        return new Forecast(
            projectedVisitors: $projectedVisitors,
            projectedSubscribers: $projectedSubscribers,
            projectedVips: $projectedVips,
            expectedBackers: $expectedBackers,
            expectedFunding: $expectedFunding,
            fundingGoal: $input->fundingGoal,
            goalCoverage: $goalCoverage,
            confidence: $this->confidence($input),
            assumptions: [
                'cpc' => $input->cpc,
                'visitor_to_subscriber_rate' => $input->visitorToSubscriberRate,
                'subscriber_to_backer_rate' => $input->subscriberToBackerRate,
                'vip_to_backer_rate' => $input->vipToBackerRate,
                'average_pledge' => $input->averagePledge,
                'planned_ad_spend' => $input->plannedAdSpend,
            ],
        );
    }

    /**
     * Build a forecast input from a project's observed data, falling back
     * to opinionated benchmarks where nothing has been measured yet.
     */
    public function inputFor(Project $project, ?int $plannedAdSpend = null): ForecastInput
    {
        $defaults = ForecastInput::defaults();

        $subscribers = $this->audience->total($project);
        $vips = $this->audience->vips($project);

        $observedCpc = $this->series->latest($project, 'cpc', 'meta');
        $observedConversion = $this->observedSignupRate($project);

        $observed = [
            $subscribers > 0,
            $observedCpc !== null,
            $observedConversion !== null,
            $project->average_pledge > 0,
        ];

        // Assumptions the creator saved take precedence over both observed
        // data and benchmarks — they know things the data does not.
        $saved = $project->forecast_assumptions ?? [];

        return new ForecastInput(
            emailSubscribers: $subscribers,
            vipCount: $vips,
            plannedAdSpend: $plannedAdSpend ?? $saved['planned_ad_spend'] ?? 1000_00,
            cpc: (float) ($saved['cpc'] ?? $observedCpc ?? $defaults['cpc']),
            visitorToSubscriberRate: (float) ($saved['visitor_to_subscriber_rate']
                ?? $observedConversion
                ?? $defaults['visitor_to_subscriber_rate']),
            subscriberToBackerRate: (float) ($saved['subscriber_to_backer_rate'] ?? $defaults['subscriber_to_backer_rate']),
            vipToBackerRate: (float) ($saved['vip_to_backer_rate'] ?? $defaults['vip_to_backer_rate']),
            averagePledge: $saved['average_pledge'] ?? ($project->average_pledge > 0 ? $project->average_pledge : 45_00),
            fundingGoal: $project->funding_goal,
            dataCompleteness: count(array_filter($observed)) / count($observed),
        );
    }

    public function forProject(Project $project, ?int $plannedAdSpend = null): Forecast
    {
        return $this->forecast($this->inputFor($project, $plannedAdSpend));
    }

    /**
     * Subscribers still needed for the expected funding to reach the goal,
     * assuming the current mix of rates. Zero when already on track.
     */
    public function subscribersNeeded(Project $project): int
    {
        $input = $this->inputFor($project, 0);
        $forecast = $this->forecast($input);

        $shortfall = $input->fundingGoal - $forecast->expectedFunding;

        if ($shortfall <= 0 || $input->averagePledge <= 0) {
            return 0;
        }

        $fundingPerSubscriber = $input->subscriberToBackerRate * $input->averagePledge;

        return $fundingPerSubscriber > 0
            ? (int) ceil($shortfall / $fundingPerSubscriber)
            : 0;
    }

    private function confidence(ForecastInput $input): string
    {
        return match (true) {
            $input->dataCompleteness >= 0.75 => 'high',
            $input->dataCompleteness >= 0.5 => 'medium',
            default => 'low',
        };
    }

    private function observedSignupRate(Project $project): ?float
    {
        $sessions = $this->series->sum($project, 'sessions', 30);

        if ($sessions <= 0) {
            return null;
        }

        $signups = $project->subscribers()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return $signups > 0 ? round($signups / $sessions, 4) : null;
    }
}
