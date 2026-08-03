<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Recommendations\AdType;

/**
 * Totals for the per-ad metric series, optionally split by the kind of ad
 * that produced them.
 *
 * Meta restates each day on every sync, so the append-only store holds
 * several observations of the same (ad, metric, day). The latest one wins
 * before anything is summed — otherwise an hourly sync would multiply the
 * month's spend by the number of times it ran.
 */
class AdTotals
{
    /**
     * Totals for the whole account, keyed by metric.
     *
     * @param  list<string>  $metrics
     * @return array<string, float>
     */
    public function total(Project $project, array $metrics, int $days = 30): array
    {
        $byType = $this->byType($project, $metrics, $days);

        $totals = array_fill_keys($metrics, 0.0);

        foreach ($byType as $ofType) {
            foreach ($ofType as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return $totals;
    }

    /**
     * Totals keyed by ad type then metric. Only types with data appear.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<string, float>>
     */
    public function byType(Project $project, array $metrics, int $days = 30): array
    {
        $snapshots = $project->metricSnapshots()
            ->where('source', 'meta')
            ->whereIn('metric', $metrics)
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        // Latest observation per ad, metric and day.
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $adId = $snapshot->dimensions['ad_id'] ?? null;

            if ($adId === null) {
                continue;
            }

            $type = AdType::tryFrom($snapshot->dimensions['ad_type'] ?? '') ?? AdType::Unknown;
            $date = $snapshot->recorded_at->toDateString();

            $latest[$type->value][$adId][$snapshot->metric][$date] = (float) $snapshot->value;
        }

        $totals = [];

        foreach ($latest as $type => $ads) {
            $totals[$type] = array_fill_keys($metrics, 0.0);

            foreach ($ads as $byMetric) {
                foreach ($byMetric as $metric => $days_) {
                    $totals[$type][$metric] += array_sum($days_);
                }
            }
        }

        return $totals;
    }
}
