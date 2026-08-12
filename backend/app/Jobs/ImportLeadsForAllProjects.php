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
 * Sweeps every project for new contacts — Meta Instant Form leads and
 * Stripe VIP purchases — and forwards them to the email provider.
 *
 * Runs on two cadences (see routes/console.php): a short-window poll every
 * few minutes so a welcome email follows a signup promptly, and a wide
 * sweep hourly to catch anything the poll missed. The window matters
 * because both providers are asked for everything inside it on every run,
 * so a 30-day lookback every five minutes would be almost entirely wasted
 * API calls.
 */
class ImportLeadsForAllProjects implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $days = 30) {}

    public function handle(ImportMetaLeads $leads, ImportStripeVips $vips): void
    {
        Integration::query()
            ->whereIn('provider', ['meta', 'stripe'])
            ->where('status', Integration::STATUS_CONNECTED)
            ->with('project')
            ->each(function (Integration $integration) use ($leads, $vips) {
                try {
                    match ($integration->provider) {
                        'meta' => $leads->handle($integration->project, $this->days),
                        'stripe' => $vips->handle($integration->project, $this->days),
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
