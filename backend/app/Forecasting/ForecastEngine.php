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
    /**
     * How many of your email list back the campaign. Nobody knows theirs
     * before launching, so rather than asking, the forecast is shown across
     * a conservative range: a cold, bought list at the bottom, an engaged
     * list that has been warmed up properly at the top.
     */
    public const BACKER_RATE_SCENARIOS = [0.10, 0.15, 0.20, 0.30];

    /** The rate a budget recommendation is built on — deliberately cautious. */
    public const PLANNING_RATE = 0.15;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
    ) {}

    /**
     * The same forecast at each plausible backer rate, so a creator sees
     * the range they are betting on rather than one false precision.
     *
     * @return list<array<string, mixed>>
     */
    public function scenarios(Project $project, ?int $plannedAdSpend = null): array
    {
        $base = $this->inputFor($project, $plannedAdSpend);

        return array_map(function (float $rate) use ($base) {
            $forecast = $this->forecast($this->withBackerRate($base, $rate));

            return [
                'rate' => $rate,
                'label' => round($rate * 100).'%',
                'expected_backers' => $forecast->expectedBackers,
                'expected_funding' => $forecast->expectedFunding,
                'goal_coverage' => $forecast->goalCoverage,
                'funds_the_goal' => $forecast->expectedFunding >= $forecast->fundingGoal,
            ];
        }, self::BACKER_RATE_SCENARIOS);
    }

    /**
     * Ad spend needed to reach the funding goal, in minor units.
     *
     * Works backwards: the goal implies a number of backers, which implies
     * a list size at the planning rate, which implies visitors at the
     * observed conversion rate, which implies spend at the observed CPC.
     * Zero when the existing list is already big enough.
     */
    public function recommendedBudget(Project $project, ?float $rate = null): int
    {
        $input = $this->inputFor($project, 0);
        $rate ??= self::PLANNING_RATE;

        if ($input->averagePledge <= 0 || $input->fundingGoal <= 0 || $rate <= 0) {
            return 0;
        }

        $backersNeeded = (int) ceil($input->fundingGoal / $input->averagePledge);
        $subscribersNeeded = (int) ceil($backersNeeded / $rate);
        $shortfall = max(0, $subscribersNeeded - $input->emailSubscribers);

        if ($shortfall === 0 || $input->visitorToSubscriberRate <= 0 || $input->cpc <= 0) {
            return 0;
        }

        $visitorsNeeded = (int) ceil($shortfall / $input->visitorToSubscriberRate);

        return (int) round($visitorsNeeded * $input->cpc * 100);
    }

    /** Whether cost per click came from this account's own ads. */
    public function hasMeasuredCpc(Project $project): bool
    {
        return $this->series->latest($project, 'cpc', 'meta') !== null;
    }

    /** Whether the visitor-to-subscriber rate came from real traffic. */
    public function hasMeasuredConversion(Project $project): bool
    {
        return $this->observedSignupRate($project) !== null;
    }

    private function withBackerRate(ForecastInput $input, float $rate): ForecastInput
    {
        return new ForecastInput(
            emailSubscribers: $input->emailSubscribers,
            vipCount: $input->vipCount,
            plannedAdSpend: $input->plannedAdSpend,
            cpc: $input->cpc,
            visitorToSubscriberRate: $input->visitorToSubscriberRate,
            subscriberToBackerRate: $rate,
            // VIPs and followers already convert better than a plain
            // subscriber; hold that advantage as the base rate moves.
            vipToBackerRate: min(1.0, $rate * 2),
            averagePledge: $input->averagePledge,
            fundingGoal: $input->fundingGoal,
            dataCompleteness: $input->dataCompleteness,
        );
    }

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
            cpc: (float) ($observedCpc ?? $defaults['cpc']),
            visitorToSubscriberRate: (float) ($observedConversion ?? $defaults['visitor_to_subscriber_rate']),
            subscriberToBackerRate: (float) $defaults['subscriber_to_backer_rate'],
            vipToBackerRate: (float) $defaults['vip_to_backer_rate'],
            averagePledge: $project->average_pledge > 0 ? $project->average_pledge : 45_00,
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

    /**
     * How many visitors become subscribers, measured rather than guessed.
     * Ad clicks are the better denominator when available: they are the
     * traffic the budget actually buys.
     */
    private function observedSignupRate(Project $project): ?float
    {
        $adClicks = $this->series->sum($project, 'ad_clicks', 30);
        $adLeads = $this->series->sum($project, 'ad_leads', 30);

        if ($adClicks > 0 && $adLeads > 0) {
            return round(min(1.0, $adLeads / $adClicks), 4);
        }

        $sessions = $this->series->sum($project, 'sessions', 30);

        if ($sessions <= 0) {
            return null;
        }

        $signups = $project->subscribers()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return $signups > 0 ? round(min(1.0, $signups / $sessions), 4) : null;
    }
}
