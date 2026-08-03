<?php

namespace App\Jobs;

use App\Actions\ImportMetaLeads;
use App\Actions\ImportStripeVips;
use App\Models\Integration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled hourly: sweeps every project for new contacts — Meta Instant
 * Form leads and Stripe VIP purchases — and forwards them to the email
 * provider.
 */
class ImportLeadsForAllProjects implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(ImportMetaLeads $leads, ImportStripeVips $vips): void
    {
        Integration::query()
            ->whereIn('provider', ['meta', 'stripe'])
            ->where('status', Integration::STATUS_CONNECTED)
            ->with('project')
            ->each(function (Integration $integration) use ($leads, $vips) {
                try {
                    match ($integration->provider) {
                        'meta' => $leads->handle($integration->project),
                        'stripe' => $vips->handle($integration->project),
                    };
                } catch (Throwable $e) {
                    // One failing account must not stop the rest.
                    Log::warning('Contact import failed for project', [
                        'project_id' => $integration->project_id,
                        'provider' => $integration->provider,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
