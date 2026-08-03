<?php

namespace App\Forecasting;

use App\Models\Project;
use App\Services\Analytics\MetricSeries;
use Illuminate\Support\Carbon;

/**
 * Turns a budget into a day-by-day plan up to launch, and tracks reality
 * against it.
 *
 * Spend is not flat. A pre-launch campaign builds a list steadily and then
 * pushes hard in the final week, when interest is highest and every new
 * subscriber is closest to the moment they can act. The weights below
 * encode that: an ordinary day counts 1, the fortnight before launch 1.5,
 * and the last seven days 3.
 */
class LaunchPlan
{
    private const WEIGHT_ORDINARY = 1.0;
    private const WEIGHT_RUN_UP = 1.5;
    private const WEIGHT_FINAL_WEEK = 3.0;

    private const RUN_UP_DAYS = 14;
    private const FINAL_WEEK_DAYS = 7;

    public function __construct(
        private readonly ForecastEngine $forecasts,
        private readonly MetricSeries $series,
    ) {}

    public function build(Project $project, ?int $plannedAdSpend = null): array
    {
        if ($project->launch_date === null) {
            return ['has_launch_date' => false, 'days' => []];
        }

        $today = now()->startOfDay();
        $launch = $project->launch_date->copy()->startOfDay();
        $daysRemaining = (int) $today->diffInDays($launch, false);

        if ($daysRemaining < 0) {
            return ['has_launch_date' => true, 'launched' => true, 'days' => []];
        }

        $input = $this->forecasts->inputFor($project, $plannedAdSpend);
        $budget = $input->plannedAdSpend;

        // A month of history behind, the whole road ahead. Anchoring to the
        // project's creation date would hide signups collected before the
        // project existed here.
        $start = $today->copy()->subDays(30);

        $weights = $this->weights($today, $launch);
        $totalWeight = array_sum($weights) ?: 1.0;

        $actuals = $this->actualsByDate($project, $start, $today);
        $startingList = $this->startingList($input, $actuals);

        $days = [];
        $cumulativeSpend = 0;
        $cumulativeWeight = 0.0;
        $projected = (float) $startingList;

        for ($date = $start->copy(); $date->lte($launch); $date->addDay()) {
            $key = $date->toDateString();
            $isFuture = $date->gt($today);

            $spend = 0;

            if ($isFuture) {
                // Round the running total rather than each day, so a hundred
                // days of rounding cannot drift the plan past the budget.
                $cumulativeWeight += $weights[$key] ?? 0;
                $target = (int) round($budget * ($cumulativeWeight / $totalWeight));
                $spend = $target - $cumulativeSpend;
                $cumulativeSpend = $target;

                $visitors = $input->cpc > 0 ? ($spend / 100) / $input->cpc : 0;
                $projected += $visitors * $input->visitorToSubscriberRate;
            }

            $days[] = [
                'date' => $key,
                'is_future' => $isFuture,
                'is_final_week' => $date->gte($launch->copy()->subDays(self::FINAL_WEEK_DAYS)) && $date->lte($launch),
                'recommended_spend' => $spend,
                'cumulative_spend' => $cumulativeSpend,
                'projected_subscribers' => $isFuture || $date->isSameDay($today) ? (int) round($projected) : null,
                'actual_subscribers' => $isFuture ? null : ($actuals[$key] ?? null),
            ];
        }

        $atLaunch = (int) round($projected);
        $target = $this->targetList($input);

        return [
            'has_launch_date' => true,
            'launched' => false,
            'launch_date' => $launch->toDateString(),
            'days_remaining' => $daysRemaining,
            'days' => $days,
            'summary' => [
                'starting_list' => $startingList,
                'projected_at_launch' => $atLaunch,
                'target_list' => $target,
                'on_track' => $atLaunch >= $target,
                'shortfall' => max(0, $target - $atLaunch),
                'daily_spend' => $this->representativeSpend($days, false),
                'final_week_daily_spend' => $this->representativeSpend($days, true),
                'total_spend' => $cumulativeSpend,
            ],
        ];
    }

    /**
     * Daily weights from tomorrow to launch. Today is excluded: the plan
     * is what to do next, not a target for a day already half spent.
     *
     * @return array<string, float>
     */
    private function weights(Carbon $today, Carbon $launch): array
    {
        $weights = [];

        for ($date = $today->copy()->addDay(); $date->lte($launch); $date->addDay()) {
            $out = (int) $date->diffInDays($launch);

            $weights[$date->toDateString()] = match (true) {
                $out < self::FINAL_WEEK_DAYS => self::WEIGHT_FINAL_WEEK,
                $out < self::RUN_UP_DAYS => self::WEIGHT_RUN_UP,
                default => self::WEIGHT_ORDINARY,
            };
        }

        return $weights;
    }

    /**
     * List size on each past day, so the plan can be tracked against what
     * actually happened.
     *
     * @return array<string, int>
     */
    private function actualsByDate(Project $project, Carbon $start, Carbon $today): array
    {
        // The provider's daily figure is authoritative where we have it.
        $reported = $this->series->daily($project, 'email_subscribers', (int) $start->diffInDays($today) + 1)
            ->keyBy('date')
            ->map(fn (array $point) => (int) $point['value']);

        $ourSignups = $project->subscribers()
            ->where('created_at', '<=', $today->copy()->endOfDay())
            ->get(['created_at'])
            ->groupBy(fn ($subscriber) => $subscriber->created_at->toDateString())
            ->map->count();

        $actuals = [];
        $running = $project->subscribers()->where('created_at', '<', $start)->count();

        for ($date = $start->copy(); $date->lte($today); $date->addDay()) {
            $key = $date->toDateString();
            $running += $ourSignups[$key] ?? 0;

            $actuals[$key] = max($running, $reported[$key] ?? 0);
        }

        return $actuals;
    }

    /**
     * Everyone the campaign can reach at launch: the email list, the
     * Kickstarter followers and the paid VIPs. The target is measured
     * against the same total, so the two must count the same people.
     */
    private function startingList(ForecastInput $input, array $actuals): int
    {
        return max($input->audience->total(), $actuals ? (int) end($actuals) : 0);
    }

    /** The list size implied by the funding goal at the planning rate. */
    private function targetList(ForecastInput $input): int
    {
        $marginal = $input->marginalBackerRate();

        if ($input->averagePledge <= 0 || $input->fundingGoal <= 0 || $marginal <= 0) {
            return 0;
        }

        $backersNeeded = (int) ceil($input->fundingGoal / $input->averagePledge);
        $alreadyHave = $input->audience->backers($input->backerRates);

        // Only the shortfall has to be built; the audience already there
        // is counted at its own segments' rates, not at the marginal one.
        $shortfall = max(0, $backersNeeded - $alreadyHave);

        return $input->audience->total() + (int) ceil($shortfall / $marginal);
    }

    /** @param  list<array<string, mixed>>  $days */
    private function representativeSpend(array $days, bool $finalWeek): int
    {
        $matching = array_filter(
            $days,
            fn (array $day) => $day['is_future'] && $day['is_final_week'] === $finalWeek,
        );

        return $matching === [] ? 0 : (int) round(array_sum(array_column($matching, 'recommended_spend')) / count($matching));
    }
}
