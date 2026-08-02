<?php

namespace App\Jobs;

use App\Events\IntegrationSynced;
use App\Integrations\IntegrationManager;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncIntegration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly Project $project,
        public readonly string $provider,
    ) {}

    public function handle(IntegrationManager $integrations): void
    {
        $result = $integrations->for($this->project, $this->provider)->sync();

        if ($result->ok) {
            IntegrationSynced::dispatch($this->project, $this->provider, $result->metricsRecorded);
        }
    }
}
