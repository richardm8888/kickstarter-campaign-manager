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
        $value = $this->cache
            ->remember(
                $this->key('latest', $project, $metric, $source),
                fn () => collect($project->metricSnapshots()
                    ->where('metric', $metric)
                    ->when($source, fn ($q) => $q->where('source', $source))
                    ->orderByDesc('recorded_at')
                    ->orderByDesc('id')
                    ->limit(1)
                    ->pluck('value')),
            )
            ->first();

        return $value === null ? null : (float) $value;
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
        return $this->cache->remember(
            $this->key('daily', $project, $metric, $source, (string) $days),
            fn () => $this->collapse(
                $project->metricSnapshots()
                    ->where('metric', $metric)
                    ->when($source, fn ($q) => $q->where('source', $source))
                    ->where('recorded_at', '>=', now()->subDays($days)->startOfDay()),
                $this->catalog->isDelta($metric),
            ),
        );
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
        $daily = $this->cache->remember(
            $this->key('window', $project, $metric, null, $from->toDateTimeString(), $to->toDateTimeString()),
            fn () => $this->collapse(
                $project->metricSnapshots()
                    ->where('metric', $metric)
                    ->whereBetween('recorded_at', [$from, $to]),
                $this->catalog->isDelta($metric),
            ),
        );

        if ($daily->isEmpty()) {
            return null;
        }

        return (float) $daily->avg('value');
    }

    /**
     * One figure per day, without ever holding the rows.
     *
     * This used to fetch the window as Eloquent models and group them in
     * memory. At production volume that is a model per snapshot — the
     * dashboard alone reached 118 MB against the container's 128 MB
     * limit at 34,000 rows, and died outright at 100,000. It was a fatal
     * error, so it arrived as a 500 with nothing in the response, and no
     * test saw it because no test has that much data.
     *
     * Streaming rows and folding them as they arrive makes the memory a
     * function of the number of days, which is bounded, rather than the
     * number of observations, which is not. Ordering by id within a day
     * is what makes "last one wins" mean the newest.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\MetricSnapshot>  $query
     * @return Collection<int, array{date: string, value: float}>
     */
    private function collapse($query, bool $isDelta): Collection
    {
        $byDay = [];

        $rows = $query->orderBy('recorded_at')
            ->orderBy('id')
            ->toBase()
            ->select('recorded_at', 'value')
            ->cursor();

        foreach ($rows as $row) {
            // Timestamps are stored and read in UTC, so the date is the
            // first ten characters whichever driver returned them.
            $date = substr((string) $row->recorded_at, 0, 10);
            $value = (float) $row->value;

            $byDay[$date] = $isDelta ? ($byDay[$date] ?? 0.0) + $value : $value;
        }

        return collect($byDay)
            ->map(fn (float $value, string $date) => ['date' => $date, 'value' => $value])
            ->values();
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
