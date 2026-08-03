<?php

namespace App\Jobs;

use App\Actions\ImportMetaLeads;
use App\Models\Integration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled hourly: sweeps every connected Meta account for new Instant
 * Form leads and forwards them to the project's email provider.
 */
class ImportLeadsForAllProjects implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(ImportMetaLeads $import): void
    {
        Integration::query()
            ->where('provider', 'meta')
            ->where('status', Integration::STATUS_CONNECTED)
            ->with('project')
            ->each(function (Integration $integration) use ($import) {
                try {
                    $import->handle($integration->project);
                } catch (Throwable $e) {
                    // One failing account must not stop the rest.
                    Log::warning('Lead import failed for project', [
                        'project_id' => $integration->project_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
