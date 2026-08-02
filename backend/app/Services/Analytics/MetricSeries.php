<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read side of the analytics store. All queries respect the
 * append-only model: "current" always means latest observation.
 */
class MetricSeries
{
    /** Latest observed value for a metric, or null when never recorded. */
    public function latest(Project $project, string $metric, ?string $source = null): ?float
    {
        $snapshot = $project->metricSnapshots()
            ->where('metric', $metric)
            ->when($source, fn ($q) => $q->where('source', $source))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        return $snapshot?->value;
    }

    /** Sum of a metric across a date window (for flow metrics like spend or sessions). */
    public function sum(Project $project, string $metric, int $days = 30, ?string $source = null): float
    {
        return (float) $project->metricSnapshots()
            ->where('metric', $metric)
            ->when($source, fn ($q) => $q->where('source', $source))
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->sum('value');
    }

    /**
     * Daily series for charting: [['date' => 'Y-m-d', 'value' => float], ...].
     * Multiple observations on one day are summed for flow metrics
     * and last-value-wins for level metrics.
     */
    public function daily(Project $project, string $metric, int $days = 30, bool $level = false): Collection
    {
        $snapshots = $project->metricSnapshots()
            ->where('metric', $metric)
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        return $snapshots
            ->groupBy(fn ($s) => $s->recorded_at->toDateString())
            ->map(fn (Collection $day, string $date) => [
                'date' => $date,
                'value' => $level ? (float) $day->last()->value : (float) $day->sum('value'),
            ])
            ->values();
    }

    /**
     * Percentage change of a metric between the previous window and the
     * current window of $days days. Null when there is no baseline.
     */
    public function changePercent(Project $project, string $metric, int $days = 7): ?float
    {
        $current = $this->windowAverage($project, $metric, now()->subDays($days), now());
        $previous = $this->windowAverage($project, $metric, now()->subDays($days * 2), now()->subDays($days));

        if ($previous === null || $previous == 0.0 || $current === null) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function windowAverage(Project $project, string $metric, Carbon $from, Carbon $to): ?float
    {
        $avg = $project->metricSnapshots()
            ->where('metric', $metric)
            ->whereBetween('recorded_at', [$from, $to])
            ->avg('value');

        return $avg === null ? null : (float) $avg;
    }
}
