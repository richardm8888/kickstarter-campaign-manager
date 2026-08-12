<?php

namespace App\Services\Analytics;

use Closure;
use Illuminate\Support\Collection;

/**
 * Remembers snapshot queries for the length of one request.
 *
 * A dashboard is a dozen services asking overlapping questions of one
 * table: the funnel wants subscribers, so does the health score, so does
 * every detector in the daily list. Each was its own round trip, and the
 * database is not on this machine — one page load was issuing the
 * identical SELECT fourteen times.
 *
 * Only identical queries are shared, so nothing reads differently than
 * it did; the repeats simply stop leaving the process. Registered as a
 * scoped binding, which means one per request and per queued job — a
 * worker that runs for a week never holds yesterday's numbers.
 */
class SnapshotCache
{
    /** @var array<string, Collection> */
    private array $entries = [];

    /**
     * @param  Closure(): Collection  $load
     */
    public function remember(string $key, Closure $load): Collection
    {
        return $this->entries[$key] ??= $load();
    }

    /**
     * Called whenever a snapshot is written.
     *
     * A sync records a metric and then, in the same process, asks what
     * the metric now says — without this, it would be answered from
     * before its own write.
     */
    public function flush(): void
    {
        $this->entries = [];
    }
}
