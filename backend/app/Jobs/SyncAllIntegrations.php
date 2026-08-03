<?php

namespace App\Jobs;

use App\Models\Integration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Scheduled hourly: fan out one SyncIntegration job per connected integration.
 */
class SyncAllIntegrations implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Integration::query()
            ->where('status', Integration::STATUS_CONNECTED)
            ->with('project')
            ->each(function (Integration $integration) {
                SyncIntegration::dispatch($integration->project, $integration->provider);
            });
    }
}
