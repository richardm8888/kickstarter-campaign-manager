<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read side of the append-only analytics store.
 *
 * Because syncs re-report the same days, reads collapse each day to a
 * single figure according to the metric's aggregation (see MetricCatalog)
 * before summing or averaging. History is preserved; only the reading of
 * it is deduplicated.
 */
class MetricSeries
{
    public function __construct(
        private readonly MetricCatalog $catalog,
        private readonly SnapshotCache $cache,
    ) {}

    /** Latest observed value for a metric, or null when never recorded. */
    public function latest(Project $project, string $metric, ?string $source = null): ?float
    {
        $snapshot = $this->cache
            ->remember(
                $this->key('latest', $project, $metric, $source),
                fn () => $project->metricSnapshots()
                    ->where('metric', $metric)
                    ->when($source, fn ($q) => $q->where('source', $source))
                    ->orderByDesc('recorded_at')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->get(),
            )
            ->first();

        return $snapshot?->value;
    }

    /**
     * Total across a window: the sum of daily figures for cumulative
     * metrics, or the latest value for level metrics.
     */
    public function sum(Project $project, string $metric, int $days = 30, ?string $source = null): float
    {
        if ($this->catalog->isLevel($metric)) {
            return $this->latest($project, $metric, $source) ?? 0.0;
        }

        return (float) $this->daily($project, $metric, $days, $source)->sum('value');
    }

    /**
     * Daily series for charting: [['date' => 'Y-m-d', 'value' => float], ...].
     * Repeated observations of a day are summed for event deltas and
     * last-one-wins for anything a provider restates.
     */
    public function daily(
        Project $project,
        string $metric,
        int $days = 30,
        ?string $source = null,
    ): Collection {
        $snapshots = $this->cache->remember(
            $this->key('daily', $project, $metric, $source, (string) $days),
            fn () => $project->metricSnapshots()
                ->where('metric', $metric)
                ->when($source, fn ($q) => $q->where('source', $source))
                ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get(),
        );

        $isDelta = $this->catalog->isDelta($metric);

        return $snapshots
            ->groupBy(fn ($s) => $s->recorded_at->toDateString())
            ->map(fn (Collection $day, string $date) => [
                'date' => $date,
                'value' => $isDelta ? (float) $day->sum('value') : (float) $day->last()->value,
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

    /** Average of the daily figures in a window, ignoring duplicate reports. */
    private function windowAverage(Project $project, string $metric, Carbon $from, Carbon $to): ?float
    {
        $snapshots = $this->cache->remember(
            $this->key('window', $project, $metric, null, $from->toDateTimeString(), $to->toDateTimeString()),
            fn () => $project->metricSnapshots()
                ->where('metric', $metric)
                ->whereBetween('recorded_at', [$from, $to])
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get(),
        );

        if ($snapshots->isEmpty()) {
            return null;
        }

        $isDelta = $this->catalog->isDelta($metric);

        $daily = $snapshots
            ->groupBy(fn ($s) => $s->recorded_at->toDateString())
            ->map(fn (Collection $day) => $isDelta ? (float) $day->sum('value') : (float) $day->last()->value);

        return (float) $daily->avg();
    }

    /**
     * Identity of a query, so two callers asking the same thing share an
     * answer. Every value the query depends on is in here — a key that
     * left one out would hand back the wrong series rather than a slow
     * one, which is the failure worth designing against.
     */
    private function key(string $shape, Project $project, string $metric, ?string $source, string ...$rest): string
    {
        return implode('|', [$shape, $project->getKey(), $metric, $source ?? '*', ...$rest]);
    }
}
