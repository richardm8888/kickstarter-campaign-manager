<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Recommendations\AdType;

/**
 * Totals for the per-ad metric series: per ad, per ad type, or whole
 * account.
 *
 * Meta restates each day on every sync, so the append-only store holds
 * several observations of the same (ad, metric, day). The latest one wins
 * before anything is summed — otherwise an hourly sync would multiply the
 * month's spend by the number of times it ran. This is the only place that
 * collapse is implemented; everything that reads per-ad data goes through
 * it.
 */
class AdTotals
{
    /**
     * One row per ad: its latest dimensions and the window's totals.
     *
     * @param  list<string>  $metrics
     * @return list<array{dimensions: array<string, mixed>, totals: array<string, float>}>
     */
    public function perAd(Project $project, array $metrics, int $days = 30): array
    {
        $snapshots = $project->metricSnapshots()
            ->where('source', 'meta')
            ->whereIn('metric', $metrics)
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $ads = [];

        foreach ($snapshots as $snapshot) {
            $adId = $snapshot->dimensions['ad_id'] ?? null;

            if ($adId === null) {
                continue;
            }

            // Names and classification can change between syncs; latest wins.
            $ads[$adId]['dimensions'] = $snapshot->dimensions;

            // Latest observation per (metric, day) wins.
            $ads[$adId]['days'][$snapshot->recorded_at->toDateString()][$snapshot->metric] = (float) $snapshot->value;
        }

        return array_values(array_map(function (array $ad) use ($metrics) {
            $totals = array_fill_keys($metrics, 0.0);

            foreach ($ad['days'] as $day) {
                foreach ($day as $metric => $value) {
                    $totals[$metric] += $value;
                }
            }

            return ['dimensions' => $ad['dimensions'], 'totals' => $totals];
        }, $ads));
    }

    /**
     * Totals keyed by ad type then metric. Only types with data appear.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<string, float>>
     */
    public function byType(Project $project, array $metrics, int $days = 30): array
    {
        $totals = [];

        foreach ($this->perAd($project, $metrics, $days) as $ad) {
            $type = (AdType::tryFrom($ad['dimensions']['ad_type'] ?? '') ?? AdType::Unknown)->value;
            $totals[$type] ??= array_fill_keys($metrics, 0.0);

            foreach ($ad['totals'] as $metric => $value) {
                $totals[$type][$metric] += $value;
            }
        }

        return $totals;
    }

    /**
     * Totals for the whole account, keyed by metric.
     *
     * @param  list<string>  $metrics
     * @return array<string, float>
     */
    public function total(Project $project, array $metrics, int $days = 30): array
    {
        $totals = array_fill_keys($metrics, 0.0);

        foreach ($this->perAd($project, $metrics, $days) as $ad) {
            foreach ($ad['totals'] as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return $totals;
    }
}
