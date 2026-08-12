<?php

namespace App\Daily;

use App\Daily\Detectors\AdEfficiencyDetector;
use App\Daily\Detectors\EmailEngagementDetector;
use App\Daily\Detectors\FunnelBottleneckDetector;
use App\Daily\Detectors\LaunchPaceDetector;
use App\Daily\Detectors\SetupGapDetector;
use App\Models\DailyTask;
use App\Models\Project;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;
use Illuminate\Support\Collection;

/**
 * Today's list.
 *
 * The hard rule is three. Not because three is special, but because a
 * list of eleven things is a list of nothing: it gets skimmed, then
 * ignored, then the tool stops being opened. Ranking is therefore the
 * product — everything below third place is deliberately thrown away, and
 * a quiet day is allowed to be quiet.
 *
 * Detection is deterministic. Every task traces to numbers that can be
 * shown, which is what makes it safe to act on one without going and
 * checking first — the point of the list is to spend the creator's
 * thinking, not to add to it.
 */
class DailyBrief
{
    /** More than this and the list stops being read. */
    public const MAX_TASKS = 3;

    public function __construct(
        private readonly SetupGapDetector $setup,
        private readonly FunnelBottleneckDetector $funnel,
        private readonly AdEfficiencyDetector $ads,
        private readonly LaunchPaceDetector $pace,
        private readonly EmailEngagementDetector $email,
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
    ) {}

    /** @return list<\App\Daily\Detector> */
    private function detectors(): array
    {
        return [$this->setup, $this->funnel, $this->ads, $this->pace, $this->email];
    }

    /**
     * Generates today's tasks, keeping anything still open and unsolved.
     *
     * @return Collection<int, DailyTask>
     */
    public function generate(Project $project): Collection
    {
        $today = now()->toDateString();

        $signals = collect($this->detectors())
            ->flatMap(fn (Detector $detector) => $detector->detect($project))
            ->reject(fn (Signal $signal) => $this->recentlyHandled($project, $signal))
            ->sortByDesc(fn (Signal $signal) => $signal->score())
            ->take(self::MAX_TASKS);

        $kept = [];

        foreach ($signals as $signal) {
            // Same problem as an open task means the same task, updated.
            // Raising it again as new work would make yesterday's untouched
            // list look like today's fresh thinking.
            $existing = $project->dailyTasks()
                ->open()
                ->where('signal_key', $signal->key)
                ->first();

            if ($existing !== null) {
                $existing->update($signal->toAttributes());
                $kept[] = $existing;

                continue;
            }

            $kept[] = $project->dailyTasks()->updateOrCreate(
                ['signal_key' => $signal->key, 'for_date' => $today],
                [...$signal->toAttributes(), 'status' => DailyTask::OPEN],
            );
        }

        // Anything still open that no detector raised today has been
        // solved by something other than the creator ticking it off — the
        // ad was paused, the page was fixed, the followers arrived. It is
        // not work any more, so it stops being shown.
        $project->dailyTasks()
            ->open()
            ->whereNotIn('id', array_column($kept, 'id'))
            ->update(['status' => DailyTask::DISMISSED]);

        return collect($kept)->sortByDesc('score')->values();
    }

    /**
     * The whole brief: what to do, how the funnel looks, and what can be
     * safely ignored.
     *
     * @return array<string, mixed>
     */
    public function build(Project $project): array
    {
        return [
            'date' => now()->toDateString(),
            'tasks' => $this->generate($project)->values(),
            'funnel_health' => $this->funnelHealth($project),
            'nothing_to_worry_about' => $this->reassurances($project),
        ];
    }

    /**
     * Suppresses a signal the creator has already dealt with.
     *
     * Doing the work rarely moves the numbers the same week, so a
     * detector firing again straight after a task was ticked off is
     * describing the evidence that prompted it, not a new problem.
     */
    private function recentlyHandled(Project $project, Signal $signal): bool
    {
        return $project->dailyTasks()
            ->where('signal_key', $signal->key)
            ->suppressing()
            ->exists();
    }

    /**
     * The few numbers worth seeing every day, with direction.
     *
     * Deliberately short. A metric earns its place by being one a creator
     * would act on, not by being available.
     *
     * @return list<array<string, mixed>>
     */
    private function funnelHealth(Project $project): array
    {
        $subscribers = $this->audience->total($project);
        $followers = $this->audience->followers($project);

        $rows = [
            [
                'key' => 'email_subscribers',
                'label' => 'Email list',
                'value' => (float) $subscribers,
                'format' => 'number',
                'direction' => Trend::of($this->series, $project, 'email_subscribers')->direction(),
            ],
            [
                'key' => 'ks_followers',
                'label' => 'Kickstarter followers',
                'value' => (float) $followers,
                'format' => 'number',
                'direction' => Trend::of($this->series, $project, 'ks_followers')->direction(),
            ],
        ];

        // A ratio, labelled as one. Nothing ties a follower to a
        // subscriber — Kickstarter hands over a total and no identities —
        // so "Email → follower" read as a measured conversion it is not.
        if ($subscribers > 0 && $project->kickstarter_url !== null) {
            $rows[] = [
                'key' => 'followers_per_hundred',
                'label' => 'Followers per 100 subscribers',
                'value' => round($followers / $subscribers * 100, 1),
                'format' => 'number',
                'direction' => 'flat',
            ];
        }

        $cpl = $this->series->latest($project, 'cpl') ?? $this->costPerLead($project);

        if ($cpl !== null) {
            $rows[] = [
                'key' => 'cost_per_lead',
                'label' => 'Cost per signup',
                'value' => round($cpl, 2),
                'format' => 'money',
                // Cheaper is better, so the arrow means the opposite here.
                'direction' => Trend::of($this->series, $project, 'cpc')->direction(),
                'lower_is_better' => true,
            ];
        }

        return $rows;
    }

    private function costPerLead(Project $project): ?float
    {
        $spend = $this->series->sum($project, 'ad_spend', 30) / 100;
        $leads = $this->series->sum($project, 'ad_leads', 30)
            + $this->series->sum($project, 'ad_follows', 30);

        return $leads > 0 && $spend > 0 ? round($spend / $leads, 2) : null;
    }

    /**
     * Up to three areas checked and found fine.
     *
     * This is not filler. Knowing what does not need attention is what
     * makes a three-item list believable rather than suspicious.
     *
     * @return list<string>
     */
    private function reassurances(Project $project): array
    {
        return collect($this->detectors())
            ->flatMap(fn (Detector $detector) => $detector->reassurances($project))
            ->unique()
            ->take(3)
            ->values()
            ->all();
    }
}
