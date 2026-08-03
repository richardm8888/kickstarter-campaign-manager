<?php

namespace App\Actions;

use App\Events\IntegrationSynced;
use App\Integrations\IntegrationManager;
use App\Integrations\SyncResult;
use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * One place where a sync is run, logged and announced — shared by the
 * queued job and the integrations:sync command so both behave identically.
 */
class RunIntegrationSync
{
    public function __construct(private readonly IntegrationManager $integrations) {}

    public function handle(Project $project, string $provider): SyncResult
    {
        $startedAt = microtime(true);

        $result = $this->integrations->for($project, $provider)->sync();

        $context = [
            'project_id' => $project->id,
            'provider' => $provider,
            'duration_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ];

        if ($result->ok) {
            Log::info('Integration sync succeeded', $context + [
                'metrics_recorded' => $result->metricsRecorded,
            ]);

            IntegrationSynced::dispatch($project, $provider, $result->metricsRecorded);
        } else {
            Log::warning('Integration sync failed', $context + ['error' => $result->error]);
        }

        return $result;
    }
}
