<?php

namespace App\Services\Analytics;

use App\Models\Project;

/**
 * Totals for a metric series that is broken down by something — an ad, a
 * referrer, a region.
 *
 * Providers restate the same day on every sync, so the append-only store
 * holds several observations of the same (segment, metric, day). The
 * latest wins before anything is summed; otherwise an hourly sync would
 * multiply a month's figures by the number of times it ran.
 *
 * This is the only implementation of that collapse. AdTotals is a thin
 * layer of ad vocabulary on top of it, and the analytics breakdowns use
 * it directly, so the two can never drift into disagreeing about what a
 * day's traffic was.
 */
class SegmentTotals
{
    /**
     * One row per distinct value of $key: its latest dimensions and the
     * window's totals. Rows without that dimension are skipped, since
     * they belong to a different shape of series.
     *
     * @param  list<string>  $metrics
     * @return list<array{dimensions: array<string, mixed>, totals: array<string, float>}>
     */
    public function get(
        Project $project,
        array $metrics,
        string $key,
        int $days = 30,
        ?string $source = null,
    ): array {
        $snapshots = $project->metricSnapshots()
            ->when($source, fn ($query) => $query->where('source', $source))
            ->whereIn('metric', $metrics)
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $segments = [];

        foreach ($snapshots as $snapshot) {
            $value = $snapshot->dimensions[$key] ?? null;

            if ($value === null) {
                continue;
            }

            // Labels and classification can change between syncs; latest wins.
            $segments[$value]['dimensions'] = $snapshot->dimensions;

            // Latest observation per (metric, day) wins.
            $segments[$value]['days'][$snapshot->recorded_at->toDateString()][$snapshot->metric]
                = (float) $snapshot->value;
        }

        return array_values(array_map(function (array $segment) use ($metrics) {
            $totals = array_fill_keys($metrics, 0.0);

            foreach ($segment['days'] as $day) {
                foreach ($day as $metric => $value) {
                    $totals[$metric] += $value;
                }
            }

            return ['dimensions' => $segment['dimensions'], 'totals' => $totals];
        }, $segments));
    }
}
