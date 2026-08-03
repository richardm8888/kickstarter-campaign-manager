<?php

namespace App\Forecasting;

use App\Models\Project;
use App\Recommendations\AdType;
use App\Services\Analytics\AdTotals;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;

/**
 * Deterministic pre-launch funding forecast.
 *
 * Model: planned ad spend buys visitors at the observed (or benchmark) CPC;
 * visitors convert to signups; signups land in one of three audiences that
 * back at very different rates (see BackerRates); backers pledge the
 * project's average pledge. No randomness — the same input always produces
 * the same forecast.
 *
 * Which audience new spend feeds is measured from the ads themselves, so a
 * creator running Kickstarter-follow ads is forecast on follower rates
 * rather than on the far weaker email-lead rate.
 */
class ForecastEngine
{
    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
        private readonly AdTotals $ads,
    ) {}

    /**
     * The same forecast under each published scenario, so a creator sees
     * the range they are betting on rather than one false precision.
     *
     * @return list<array<string, mixed>>
     */
    public function scenarios(Project $project, ?int $plannedAdSpend = null): array
    {
        $base = $this->inputFor($project, $plannedAdSpend);

        return array_map(function (string $name) use ($base) {
            $rates = BackerRates::scenario($name);
            $forecast = $this->forecast($this->withBackerRates($base, $rates));

            return [
                'scenario' => $name,
                'label' => BackerRates::label($name),
                'rates' => $rates,
                'expected_backers' => $forecast->expectedBackers,
                'backers_by_segment' => $forecast->backersBySegment,
                'expected_funding' => $forecast->expectedFunding,
                'goal_coverage' => $forecast->goalCoverage,
                'funds_the_goal' => $forecast->expectedFunding >= $forecast->fundingGoal,
                'is_planning' => $name === BackerRates::PLANNING_SCENARIO,
            ];
        }, BackerRates::scenarioNames());
    }

    /**
     * What the audience is worth today, segment by segment, at the planning
     * rates. This is where the gap between a follower and a plain email
     * address becomes obvious.
     */
    public function audienceValue(Project $project): array
    {
        $input = $this->inputFor($project, 0);
        $counts = $input->audience->counts();

        $segments = [];

        foreach (BackerRates::segments() as $segment) {
            $count = $counts[$segment];
            $rate = $input->backerRates[$segment];
            [$low, $high] = BackerRates::range($segment);

            $segments[] = [
                'segment' => $segment,
                'label' => BackerRates::segmentLabel($segment),
                'count' => $count,
                'rate' => $rate,
                'rate_low' => $low,
                'rate_high' => $high,
                'backers' => (int) floor($count * $rate),
                'funding' => (int) floor($count * $rate) * $input->averagePledge,
                // What one more of these is worth, in minor units.
                'value_each' => (int) round($rate * $input->averagePledge),
            ];
        }

        return $segments;
    }

    /**
     * Ad spend needed to reach the funding goal, in minor units.
     *
     * Works backwards: the goal implies backers, backers imply signups at
     * the rate this project's ads actually deliver, signups imply visitors
     * at the observed conversion, visitors imply spend at the observed CPC.
     * Zero when the audience is already big enough.
     */
    public function recommendedBudget(Project $project): int
    {
        $input = $this->inputFor($project, 0);

        if ($input->averagePledge <= 0 || $input->fundingGoal <= 0) {
            return 0;
        }

        $backersNeeded = (int) ceil($input->fundingGoal / $input->averagePledge);
        $alreadyHave = $input->audience->backers($input->backerRates);
        $shortfall = max(0, $backersNeeded - $alreadyHave);

        $marginal = $input->marginalBackerRate();

        if ($shortfall === 0 || $marginal <= 0 || $input->visitorToSubscriberRate <= 0 || $input->cpc <= 0) {
            return 0;
        }

        $signupsNeeded = (int) ceil($shortfall / $marginal);
        $visitorsNeeded = (int) ceil($signupsNeeded / $input->visitorToSubscriberRate);

        return (int) round($visitorsNeeded * $input->cpc * 100);
    }

    /**
     * How much the forecast can be trusted.
     *
     * Having a number is not the same as having a reliable one: a rate
     * measured from a handful of clicks is noise. Confidence therefore
     * turns on sample size, not merely on whether data exists.
     */
    private function dataQuality(Project $project, bool $cpcMeasured, bool $conversionMeasured): float
    {
        if (! $cpcMeasured || ! $conversionMeasured) {
            return 0.2;
        }

        $clicks = $this->series->sum($project, 'ad_clicks', 30);
        $signups = $this->series->sum($project, 'ad_leads', 30);

        return match (true) {
            $clicks >= 500 && $signups >= 30 => 1.0,
            $clicks >= 100 && $signups >= 10 => 0.6,
            default => 0.2,
        };
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

    /**
     * What has to be true for the planned budget to fund the goal, and how
     * realistic each of those is.
     *
     * Two levers, holding the other still: the share of visitors who sign
     * up, and the share of the resulting audience who back. Stating both
     * makes the bet explicit.
     */
    public function requirements(Project $project, ?int $plannedAdSpend = null): array
    {
        $input = $this->inputFor($project, $plannedAdSpend);

        if ($input->averagePledge <= 0 || $input->fundingGoal <= 0) {
            return [];
        }

        $backersNeeded = (int) ceil($input->fundingGoal / $input->averagePledge);
        $visitors = $input->cpc > 0 ? (int) floor($input->plannedAdSpend / 100 / $input->cpc) : 0;
        $marginal = $input->marginalBackerRate();

        // Signups needed on top of what the audience already delivers.
        $alreadyHave = $input->audience->backers($input->backerRates);
        $shortfall = max(0, $backersNeeded - $alreadyHave);
        $signupsNeeded = $marginal > 0 ? (int) ceil($shortfall / $marginal) : 0;
        $requiredConversion = $visitors > 0 ? $signupsNeeded / $visitors : null;

        // Backer rate needed across the whole projected audience, if
        // conversion stays where it is measured.
        $projected = $this->projectedAudience($input, $visitors);
        $requiredBackerRate = $projected->total() > 0 ? $backersNeeded / $projected->total() : null;

        // What that audience can actually do at the top of every range.
        $ceiling = $projected->blendedRate(BackerRates::scenario(BackerRates::OPTIMISTIC));

        return [
            'backers_needed' => $backersNeeded,
            'backers_from_current_audience' => $alreadyHave,
            'signups_needed' => $signupsNeeded,
            'projected_list' => $projected->total(),
            'projected_mix' => $projected->counts(),
            'visitors_bought' => $visitors,
            'marginal_backer_rate' => round($marginal, 4),
            'required_conversion' => $requiredConversion === null ? null : [
                'rate' => round($requiredConversion, 4),
                'current' => $input->visitorToSubscriberRate,
                'likelihood' => $this->conversionLikelihood($requiredConversion, $input->visitorToSubscriberRate),
            ],
            'required_backer_rate' => $requiredBackerRate === null ? null : [
                'rate' => round($requiredBackerRate, 4),
                'planning' => round($projected->blendedRate($input->backerRates), 4),
                'ceiling' => round($ceiling, 4),
                'likelihood' => $this->backerRateLikelihood($requiredBackerRate, $projected, $input->backerRates),
            ],
        ];
    }

    /** Judged against what this project already achieves, and what is achievable at all. */
    private function conversionLikelihood(float $required, float $current): string
    {
        return match (true) {
            $required <= $current => 'already there',
            $required <= 0.10 => 'plausible',
            $required <= 0.25 => 'a stretch',
            default => 'unrealistic',
        };
    }

    /**
     * Judged against what this particular mix can do. A list of plain email
     * addresses tops out near 3% however well it is run, so a plan needing
     * 8% from it is not ambitious — it is impossible, and saying so is more
     * useful than a percentage.
     *
     * @param  array<string, float>  $planningRates
     */
    private function backerRateLikelihood(float $required, AudienceMix $mix, array $planningRates): string
    {
        $planning = $mix->blendedRate($planningRates);
        $ceiling = $mix->blendedRate(BackerRates::scenario(BackerRates::OPTIMISTIC));

        return match (true) {
            $required <= $planning => 'likely',
            $required <= ($planning + $ceiling) / 2 => 'plausible',
            $required <= $ceiling => 'a stretch',
            default => 'unrealistic',
        };
    }

    /** @param  array<string, float>  $rates */
    private function withBackerRates(ForecastInput $input, array $rates): ForecastInput
    {
        return new ForecastInput(
            audience: $input->audience,
            plannedAdSpend: $input->plannedAdSpend,
            cpc: $input->cpc,
            visitorToSubscriberRate: $input->visitorToSubscriberRate,
            backerRates: $rates,
            averagePledge: $input->averagePledge,
            fundingGoal: $input->fundingGoal,
            dataCompleteness: $input->dataCompleteness,
            adMix: $input->adMix,
        );
    }

    public function forecast(ForecastInput $input): Forecast
    {
        $projectedVisitors = $input->cpc > 0
            ? (int) floor($input->plannedAdSpend / 100 / $input->cpc)
            : 0;

        $projected = $this->projectedAudience($input, $projectedVisitors);

        $bySegment = [];

        foreach (BackerRates::segments() as $segment) {
            $bySegment[$segment] = (int) floor(
                $projected->counts()[$segment] * ($input->backerRates[$segment] ?? 0.0)
            );
        }

        $expectedBackers = $projected->backers($input->backerRates);
        $expectedFunding = $expectedBackers * $input->averagePledge;

        $goalCoverage = $input->fundingGoal > 0
            ? round($expectedFunding / $input->fundingGoal, 3)
            : 0.0;

        return new Forecast(
            projectedVisitors: $projectedVisitors,
            projectedSubscribers: $projected->standard + $projected->vips,
            projectedVips: $projected->vips,
            projectedFollowers: $projected->followers,
            expectedBackers: $expectedBackers,
            expectedFunding: $expectedFunding,
            fundingGoal: $input->fundingGoal,
            goalCoverage: $goalCoverage,
            confidence: $this->confidence($input),
            assumptions: [
                'cpc' => $input->cpc,
                'visitor_to_subscriber_rate' => $input->visitorToSubscriberRate,
                'backer_rates' => $input->backerRates,
                'marginal_backer_rate' => round($input->marginalBackerRate(), 4),
                'average_pledge' => $input->averagePledge,
                'planned_ad_spend' => $input->plannedAdSpend,
            ],
            backersBySegment: $bySegment,
        );
    }

    /**
     * The audience after the planned spend, split the way this project's
     * ads actually split it.
     */
    public function projectedAudience(ForecastInput $input, int $visitors): AudienceMix
    {
        $newSignups = (int) floor($visitors * $input->visitorToSubscriberRate);
        $mix = $input->adMix ?? [BackerRates::STANDARD => 1.0];

        return $input->audience->withExtraStandard(
            (int) floor($newSignups * ($mix[BackerRates::STANDARD] ?? 0.0)),
            (int) floor($newSignups * ($mix[BackerRates::FOLLOWERS] ?? 0.0)),
            (int) floor($newSignups * ($mix[BackerRates::VIPS] ?? 0.0)),
        );
    }

    /**
     * Build a forecast input from a project's observed data, falling back
     * to opinionated benchmarks where nothing has been measured yet.
     */
    public function inputFor(Project $project, ?int $plannedAdSpend = null): ForecastInput
    {
        $defaults = ForecastInput::defaults();

        $observedCpc = $this->series->latest($project, 'cpc', 'meta');
        $observedConversion = $this->observedSignupRate($project);

        // Assumptions the creator saved take precedence over benchmarks —
        // they know things the data does not.
        $saved = $project->forecast_assumptions ?? [];

        return new ForecastInput(
            audience: AudienceMix::fromList(
                emailSubscribers: $this->audience->total($project),
                followers: $this->audience->followers($project),
                vips: $this->audience->vips($project),
            ),
            plannedAdSpend: $plannedAdSpend ?? $saved['planned_ad_spend'] ?? 1000_00,
            cpc: (float) ($observedCpc ?? $defaults['cpc']),
            visitorToSubscriberRate: (float) ($observedConversion ?? $defaults['visitor_to_subscriber_rate']),
            backerRates: BackerRates::planning(),
            averagePledge: $project->average_pledge > 0 ? $project->average_pledge : 45_00,
            fundingGoal: $project->funding_goal,
            dataCompleteness: $this->dataQuality(
                $project,
                $observedCpc !== null,
                $observedConversion !== null,
            ),
            adMix: $this->adMix($project),
        );
    }

    /**
     * Where this project's ads send new signups, as shares summing to one.
     *
     * Null when no ads have produced anything yet, which leaves the
     * forecast on the cautious assumption that spend buys plain email
     * leads — the least valuable of the three.
     *
     * @return array<string, float>|null
     */
    public function adMix(Project $project): ?array
    {
        $byType = $this->ads->byType($project, ['ad_leads', 'ad_follows'], 30);

        $followers = 0.0;
        $standard = 0.0;

        foreach ($byType as $type => $totals) {
            if (AdType::tryFrom($type) === AdType::Kickstarter) {
                $followers += $totals['ad_follows'];
                continue;
            }

            $standard += $totals['ad_leads'];
        }

        // Follows can also arrive from Kickstarter ads counted elsewhere.
        $total = $standard + $followers;

        if ($total <= 0) {
            return null;
        }

        return [
            // VIPs are bought with a card, not with an ad click, so ad
            // spend never adds to that segment directly.
            BackerRates::STANDARD => round($standard / $total, 4),
            BackerRates::FOLLOWERS => round($followers / $total, 4),
            BackerRates::VIPS => 0.0,
        ];
    }

    public function forProject(Project $project, ?int $plannedAdSpend = null): Forecast
    {
        return $this->forecast($this->inputFor($project, $plannedAdSpend));
    }

    /**
     * Signups still needed for the expected funding to reach the goal, at
     * the rate this project's ads deliver. Zero when already on track.
     */
    public function subscribersNeeded(Project $project): int
    {
        $input = $this->inputFor($project, 0);
        $forecast = $this->forecast($input);

        $shortfall = $input->fundingGoal - $forecast->expectedFunding;
        $marginal = $input->marginalBackerRate();

        if ($shortfall <= 0 || $input->averagePledge <= 0 || $marginal <= 0) {
            return 0;
        }

        return (int) ceil($shortfall / ($marginal * $input->averagePledge));
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
     * How many visitors become signups, measured rather than guessed.
     * Ad clicks are the better denominator when available: they are the
     * traffic the budget actually buys. Follows count as signups — they
     * are a person the campaign captured, just on Kickstarter's platform.
     */
    private function observedSignupRate(Project $project): ?float
    {
        $adClicks = $this->series->sum($project, 'ad_clicks', 30);
        $adSignups = $this->series->sum($project, 'ad_leads', 30)
            + $this->series->sum($project, 'ad_follows', 30);

        if ($adClicks > 0 && $adSignups > 0) {
            return round(min(1.0, $adSignups / $adClicks), 4);
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
