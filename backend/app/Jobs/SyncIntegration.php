<?php

namespace App\Jobs;

use App\Actions\RunIntegrationSync;
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

    public function handle(RunIntegrationSync $sync): void
    {
        $sync->handle($this->project, $this->provider);
    }
}
