<?php

namespace App\Daily\Detectors;

use App\Daily\Detector;
use App\Daily\Signal;
use App\Daily\Trend;
use App\Forecasting\LaunchPlan;
use App\Models\DailyTask;
use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * Whether the audience is growing fast enough to matter by launch day.
 *
 * Everything else in this list is about efficiency. This is about time,
 * which is the one input a pre-launch campaign cannot buy back: a gap
 * noticed eight weeks out is a plan, and the same gap noticed eight days
 * out is a smaller campaign.
 */
class LaunchPaceDetector implements Detector
{
    /** Inside this, there is no longer time to fix growth with patience. */
    private const CRUNCH_DAYS = 21;

    /** Beyond this the forecast is too speculative to raise work from. */
    private const HORIZON_DAYS = 120;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly LaunchPlan $plan,
    ) {}

    /** @return list<Signal> */
    public function detect(Project $project): array
    {
        return array_values(array_filter([
            $this->behindPace($project),
            $this->followerGrowthStalled($project),
        ]));
    }

    /** The forecast does not reach the goal, with time still to act. */
    private function behindPace(Project $project): ?Signal
    {
        $days = $this->daysToLaunch($project);

        if ($days === null || $days > self::HORIZON_DAYS) {
            return null;
        }

        $plan = $this->plan->build($project);
        $summary = $plan['summary'] ?? null;

        if ($summary === null || ($summary['on_track'] ?? false)) {
            return null;
        }

        $shortfall = (int) ($summary['shortfall'] ?? 0);

        if ($shortfall <= 0) {
            return null;
        }

        $crunch = $days <= self::CRUNCH_DAYS;

        return new Signal(
            key: 'launch_pace_behind',
            title: $crunch
                ? sprintf('Decide now: %d days left and %s short', $days, number_format($shortfall))
                : sprintf('Audience is %s short of the launch target', number_format($shortfall)),
            why: sprintf(
                'At the current rate you reach about %s by launch against a target of %s, %d days out.%s',
                number_format((int) ($summary['projected_at_launch'] ?? 0)),
                number_format((int) ($summary['target_list'] ?? 0)),
                $days,
                $crunch
                    ? ' There is no longer enough runway for growth to close this on its own.'
                    : '',
            ),
            action: $crunch
                ? 'Pick one: raise the daily budget for the final stretch, cut the funding goal to something this audience can fund, or move the launch date. Doing none of the three is a decision too.'
                : 'Open the launch plan and raise the daily spend to the figure it recommends, or extend the pre-launch period by a fortnight.',
            effortMinutes: $crunch ? 30 : 15,
            impact: DailyTask::HIGH,
            confidence: 0.7,
            urgency: $crunch ? 0.95 : 0.5,
            evidence: [
                'days_to_launch' => $days,
                'projected_at_launch' => $summary['projected_at_launch'] ?? null,
                'target_list' => $summary['target_list'] ?? null,
                'shortfall' => $shortfall,
            ],
        );
    }

    /**
     * Followers flat while the campaign is being marketed. Raised only
     * where there is a page to follow and effort going in — a project not
     * yet advertising has nothing to diagnose.
     */
    private function followerGrowthStalled(Project $project): ?Signal
    {
        if ($project->kickstarter_url === null) {
            return null;
        }

        $followers = Trend::of($this->series, $project, 'ks_followers');
        $spend = Trend::of($this->series, $project, 'ad_spend');

        if ($spend->recent <= 0 || ! $followers->isEstablished()) {
            return null;
        }

        if (! $followers->flat(3) && ! $followers->falling(1)) {
            return null;
        }

        return new Signal(
            key: 'followers_flat_under_spend',
            title: 'Spend is going out and followers are not moving',
            why: 'The follower count has been effectively flat for a week while ads kept running. Whatever the budget is buying, it is not reaching the Kickstarter page.',
            action: 'Check where your ads actually send people. If they point at a landing page or an instant form, add one ad pointing straight at the pre-launch page — a follow is worth roughly ten email addresses.',
            effortMinutes: 20,
            impact: DailyTask::HIGH,
            confidence: 0.65,
            urgency: 0.75,
            evidence: [
                'followers' => (int) $followers->recent,
                'follower_change_percent' => $followers->changePercent,
                'recent_spend' => (int) $spend->recent,
            ],
        );
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        $notes = [];

        $plan = $this->plan->build($project);

        if (($plan['summary']['on_track'] ?? false) === true) {
            $notes[] = 'Audience growth is on pace for the launch target.';
        }

        $followers = Trend::of($this->series, $project, 'ks_followers');

        if ($followers->rising(5)) {
            $notes[] = sprintf(
                'Kickstarter followers are up %s%% on the previous fortnight.',
                (int) $followers->changePercent,
            );
        }

        return $notes;
    }

    private function daysToLaunch(Project $project): ?int
    {
        if ($project->launch_date === null) {
            return null;
        }

        $days = (int) ceil(now()->startOfDay()->diffInDays($project->launch_date, false));

        return $days >= 0 ? $days : null;
    }
}
