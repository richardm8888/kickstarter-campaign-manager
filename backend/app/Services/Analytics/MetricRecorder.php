<?php

namespace App\Services\Analytics;

use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * Single write path for analytics. Snapshots are append-only:
 * new observations are inserted, existing rows are never mutated.
 */
class MetricRecorder
{
    public function record(
        Project $project,
        string $source,
        string $metric,
        float $value,
        Carbon|string|null $recordedAt = null,
        ?array $dimensions = null,
    ): MetricSnapshot {
        return $project->metricSnapshots()->create([
            'source' => $source,
            'metric' => $metric,
            'value' => $value,
            'recorded_at' => $recordedAt ? Carbon::parse($recordedAt) : now(),
            'dimensions' => $dimensions,
        ]);
    }
}
