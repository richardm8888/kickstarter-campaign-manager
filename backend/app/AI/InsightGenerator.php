<?php

namespace App\AI;

use App\AI\Contracts\AiProvider;
use App\Forecasting\ForecastEngine;
use App\Models\Insight;
use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * Turns raw metric movements into plain-English insights with a
 * recommended action. Signal detection is deterministic and rule-based;
 * the AI provider (when configured) only rewrites the copy to be sharper.
 */
class InsightGenerator
{
    public function __construct(
        private readonly MetricSeries $series,
        private readonly ForecastEngine $forecasts,
        private readonly AiProvider $ai,
    ) {}

    /** @return list<Insight> newly created insights */
    public function generate(Project $project): array
    {
        $created = [];

        foreach ($this->detectSignals($project) as $signal) {
            if ($this->alreadyRaisedToday($project, $signal['key'])) {
                continue;
            }

            $created[] = $project->insights()->create([
                'kind' => Insight::KIND_INSIGHT,
                'severity' => $signal['severity'],
                'title' => $signal['title'],
                'body' => $this->polish($signal['body']),
                'action' => $signal['action'],
                'metadata' => ['signal' => $signal['key']],
            ]);
        }

        return $created;
    }

    /** @return list<array{key:string,severity:string,title:string,body:string,action:string}> */
    private function detectSignals(Project $project): array
    {
        $signals = [];

        if (($cpcChange = $this->series->changePercent($project, 'cpc')) !== null && abs($cpcChange) >= 15) {
            $up = $cpcChange > 0;
            $signals[] = [
                'key' => 'cpc_change',
                'severity' => $up ? 'warning' : 'success',
                'title' => sprintf('Your Meta CPC %s %s%%', $up ? 'increased' : 'dropped', abs($cpcChange)),
                'body' => $up
                    ? 'Paying more per click means fewer subscribers for the same budget. This usually follows creative fatigue or a broadened audience.'
                    : 'Cheaper clicks mean your budget now buys more subscribers. Whatever changed is working.',
                'action' => $up
                    ? 'Refresh your ad creatives and review recent audience changes.'
                    : 'Consider scaling budget while clicks stay cheap.',
            ];
        }

        if (($needed = $this->forecasts->subscribersNeeded($project)) > 0) {
            $signals[] = [
                'key' => 'subscribers_needed',
                'severity' => 'info',
                'title' => sprintf('You need another %d signups to reach your funding goal', $needed),
                'body' => 'Based on what your ads deliver and your average pledge, your audience is not yet large enough to fund the campaign.',
                'action' => 'Increase ad spend or add a referral push to close the gap before launch.',
            ];
        }

        if ($cohort = $this->cohortEngagementSignal($project)) {
            $signals[] = $cohort;
        }

        if ($this->noSignupsInLastDay($project)) {
            $signals[] = [
                'key' => 'no_signups_24h',
                'severity' => 'warning',
                'title' => 'No email signups in the last 24 hours',
                'body' => 'Your list has stalled. Momentum matters — a silent day this close to launch is worth investigating.',
                'action' => 'Check your ads are delivering and the signup form still works.',
            ];
        }

        return $signals;
    }

    /**
     * Compares how Meta form leads engage against the rest of the list.
     * A cheap lead that never opens an email is not cheap.
     */
    private function cohortEngagementSignal(Project $project): ?array
    {
        $formRate = $this->series->latest($project, 'email_form_open_rate');
        $otherRate = $this->series->latest($project, 'email_other_open_rate');

        if ($formRate === null || $otherRate === null || $otherRate <= 0.0) {
            return null;
        }

        $difference = round(($formRate - $otherRate) / $otherRate * 100);

        if (abs($difference) < 20) {
            return null;
        }

        $worse = $difference < 0;

        return [
            'key' => 'form_lead_engagement',
            'severity' => $worse ? 'warning' : 'success',
            'title' => sprintf(
                'Meta form leads open %s%% %s than your other subscribers',
                abs($difference),
                $worse ? 'less' : 'more',
            ),
            'body' => sprintf(
                'Form leads open at %s%% against %s%% for everyone else. %s',
                $formRate,
                $otherRate,
                $worse
                    ? 'People who hand over an email inside Facebook often forget they did, so they need a warmer welcome than the rest of your list.'
                    : 'That audience is engaged — worth more budget.',
            ),
            'action' => $worse
                ? 'Send form leads a dedicated welcome email reminding them what they signed up for.'
                : 'Shift more budget into the lead form campaigns.',
        ];
    }

    private function alreadyRaisedToday(Project $project, string $key): bool
    {
        return $project->insights()
            ->where('created_at', '>=', now()->startOfDay())
            ->where('metadata->signal', $key)
            ->exists();
    }

    private function noSignupsInLastDay(Project $project): bool
    {
        if ($project->subscribers()->active()->count() === 0) {
            return false; // nothing has started yet; a different insight covers that
        }

        return ! $project->subscribers()
            ->active()
            ->where('created_at', '>=', now()->subDay())
            ->exists();
    }

    private function polish(string $body): string
    {
        $polished = $this->ai->complete(
            'You are a Kickstarter launch advisor. Rewrite the given insight for a creator: concise, concrete, encouraging but honest. Reply with the rewritten text only.',
            $body,
        );

        return $polished ?: $body;
    }
}
