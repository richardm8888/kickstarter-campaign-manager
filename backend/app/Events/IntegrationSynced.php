<?php

namespace App\Events;

use App\Models\Project;
use Illuminate\Foundation\Events\Dispatchable;

class IntegrationSynced
{
    use Dispatchable;

    public function __construct(
        public readonly Project $project,
        public readonly string $provider,
        public readonly int $metricsRecorded,
    ) {}
}
