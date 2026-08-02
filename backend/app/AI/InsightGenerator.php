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

        if (($convChange = $this->series->changePercent($project, 'signup_rate')) !== null && $convChange <= -15) {
            $signals[] = [
                'key' => 'conversion_drop',
                'severity' => 'critical',
                'title' => sprintf('Landing page conversion dropped %s%%', abs($convChange)),
                'body' => 'Fewer visitors are becoming subscribers than last week. Recent page changes or colder traffic are the usual causes.',
                'action' => 'Review your latest landing page edits and traffic sources.',
            ];
        }

        if (($needed = $this->forecasts->subscribersNeeded($project)) > 0) {
            $signals[] = [
                'key' => 'subscribers_needed',
                'severity' => 'info',
                'title' => sprintf('You need another %d subscribers to reach your funding goal', $needed),
                'body' => 'Based on your current conversion rates and average pledge, your list is not yet large enough to fund the campaign.',
                'action' => 'Increase ad spend or add a referral push to close the gap before launch.',
            ];
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

    private function alreadyRaisedToday(Project $project, string $key): bool
    {
        return $project->insights()
            ->where('created_at', '>=', now()->startOfDay())
            ->where('metadata->signal', $key)
            ->exists();
    }

    private function noSignupsInLastDay(Project $project): bool
    {
        if ($project->subscribers()->count() === 0) {
            return false; // nothing has started yet; a different insight covers that
        }

        return ! $project->subscribers()
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
