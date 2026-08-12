<?php

namespace App\Daily\Detectors;

use App\Daily\Detector;
use App\Daily\Signal;
use App\Daily\Trend;
use App\Models\DailyTask;
use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Analytics\MetricSeries;

/**
 * Money being wasted, and money worth spending more of.
 *
 * The per-ad verdicts already exist; this turns the ones that need a
 * decision into work, and deliberately ignores the rest. An ad doing its
 * job is not a todo.
 *
 * Nothing here recommends a budget change on cost alone. A cheap lead
 * that never follows the Kickstarter page is not a cheap lead, it is a
 * cheap distraction, so scaling is only ever suggested where the ad is
 * feeding the part of the funnel that actually produces backers.
 */
class AdEfficiencyDetector implements Detector
{
    /**
     * Below this, a verdict is drawn from too little spend to trust.
     *
     * Currency units, not minor units: Meta reports spend as "12.34" and
     * nothing converts it. Written as 20_00 this read as a £2,000 floor,
     * which no pre-launch creator reaches, so these signals never fired —
     * and a detector that silently never fires looks exactly like a
     * campaign with nothing wrong.
     */
    private const MINIMUM_SPEND = 20.0;

    /** A rise in cost this large over a week is fatigue, not noise. */
    private const FATIGUE_CPC_RISE = 20.0;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly AdPerformanceAnalyser $ads,
    ) {}

    /** @return list<Signal> */
    public function detect(Project $project): array
    {
        return array_values(array_filter([
            $this->wastedSpend($project),
            $this->creativeFatigue($project),
            $this->worthScaling($project),
        ]));
    }

    /** Ads the analyser would drop, weighted by what they are costing. */
    private function wastedSpend(Project $project): ?Signal
    {
        $losers = $this->adsWithVerdict($project, 'drop');

        if ($losers === []) {
            return null;
        }

        $wasted = array_sum(array_column($losers, 'spend'));

        if ($wasted < self::MINIMUM_SPEND) {
            return null;
        }

        $worst = $losers[0];

        return new Signal(
            key: 'ads_wasted_spend',
            title: count($losers) === 1
                ? sprintf('Turn off "%s"', $worst['ad_name'])
                : sprintf('Turn off %d underperforming ads', count($losers)),
            why: sprintf(
                '%s spent %s in the last fortnight for results that are not paying their way. %s',
                count($losers) === 1 ? 'It has' : 'They have',
                $this->money($wasted, $project),
                $worst['reason'],
            ),
            action: count($losers) === 1
                ? $worst['action']
                : 'Pause them in Meta and move the budget to whichever ad has the lowest cost per Kickstarter follower.',
            effortMinutes: 10,
            impact: DailyTask::HIGH,
            // The verdict is arithmetic on spend that already happened.
            confidence: 0.9,
            // Every day it stays on costs the same money again.
            urgency: 0.9,
            evidence: [
                'ads' => array_column($losers, 'ad_name'),
                'wasted_spend' => $wasted,
            ],
        );
    }

    /**
     * Rising cost per click with falling click-through: the audience has
     * seen it too often. Caught early this is a ten-minute swap; left
     * alone it quietly doubles the cost of everything downstream.
     */
    private function creativeFatigue(Project $project): ?Signal
    {
        $cpc = Trend::of($this->series, $project, 'cpc');
        $ctr = Trend::of($this->series, $project, 'ctr');

        if (! $cpc->rising(self::FATIGUE_CPC_RISE) || ! $ctr->falling(10)) {
            return null;
        }

        return new Signal(
            key: 'ads_creative_fatigue',
            title: 'Refresh your ad creative',
            why: sprintf(
                'Cost per click is up %s%% over the last week while click-through is down %s%%. Both moving together is the usual signature of an audience that has seen the creative too many times.',
                (int) $cpc->changePercent,
                abs((int) $ctr->changePercent),
            ),
            action: 'Duplicate your best-performing ad and swap the image or opening line. Leave the targeting alone so the change is readable.',
            effortMinutes: 20,
            impact: DailyTask::MEDIUM,
            confidence: 0.75,
            urgency: 0.7,
            evidence: [
                'cpc_change_percent' => $cpc->changePercent,
                'ctr_change_percent' => $ctr->changePercent,
            ],
        );
    }

    /** An ad earning more budget, but only if it feeds the right stage. */
    private function worthScaling(Project $project): ?Signal
    {
        $winners = $this->adsWithVerdict($project, 'scale');

        if ($winners === []) {
            return null;
        }

        $best = $winners[0];

        // Cheap leads that never become followers are not worth buying
        // more of, and this is the single most common way a pre-launch
        // budget gets spent on an audience that will not back.
        $feedsBackers = in_array($best['ad_type'], ['kickstarter', 'instant_form'], true)
            || $best['follows'] > 0;

        if (! $feedsBackers) {
            return null;
        }

        return new Signal(
            key: 'ads_worth_scaling',
            title: sprintf('Raise the budget on "%s"', $best['ad_name']),
            why: sprintf('%s %s', $best['reason'], $best['follows'] > 0
                ? sprintf('It has produced %d Kickstarter follows, so the spend is reaching the audience that actually backs.', $best['follows'])
                : 'It feeds the Kickstarter page directly rather than a general email list.'),
            action: 'Raise its daily budget by around 20% in Meta. Larger jumps reset the learning phase and cost more than they buy.',
            effortMinutes: 5,
            impact: DailyTask::MEDIUM,
            confidence: 0.7,
            // Nothing breaks by waiting a day.
            urgency: 0.35,
            evidence: [
                'ad_name' => $best['ad_name'],
                'spend' => $best['spend'],
                'follows' => $best['follows'],
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function adsWithVerdict(Project $project, string $verdict): array
    {
        $report = $this->ads->analyse($project);

        return array_values(array_filter(
            $report['ads'] ?? [],
            fn (array $ad) => $ad['verdict'] === $verdict && $ad['spend'] >= self::MINIMUM_SPEND,
        ));
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        $report = $this->ads->analyse($project);
        $ads = $report['ads'] ?? [];

        if ($ads === []) {
            return [];
        }

        $needingWork = array_filter(
            $ads,
            fn (array $ad) => in_array($ad['verdict'], ['drop', 'fix'], true),
        );

        if ($needingWork !== []) {
            return [];
        }

        $cpl = $report['benchmark']['account_cpl'] ?? null;
        $affordable = $report['benchmark']['affordable_cpl'] ?? null;

        if ($cpl !== null && $affordable !== null && $cpl <= $affordable) {
            return [sprintf(
                'Paid acquisition is inside what a signup is worth to you (%s against %s).',
                $this->money($cpl, $project),
                $this->money($affordable, $project),
            )];
        }

        return ['No ad currently needs a decision.'];
    }

    /** @param  float  $amount  currency units, as Meta reports spend */
    private function money(float $amount, Project $project): string
    {
        $symbol = match (strtoupper($project->currency)) {
            'USD' => '$',
            'EUR' => '€',
            default => '£',
        };

        return $symbol.number_format($amount, 2);
    }
}
