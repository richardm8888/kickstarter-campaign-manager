<?php

namespace App\Console\Commands;

use App\Actions\RunIntegrationSync;
use App\Jobs\SyncIntegration;
use App\Models\Integration;
use Illuminate\Console\Command;

/**
 * Runs integration syncs on demand and prints the outcome — the quickest
 * way to see why a provider is not returning data.
 */
class SyncIntegrationsCommand extends Command
{
    protected $signature = 'integrations:sync
        {--project= : Only this project id}
        {--provider= : Only this provider (meta, ga4, mailerlite, stripe)}
        {--queue : Dispatch to the queue instead of running immediately}';

    protected $description = 'Sync connected integrations and report what each one returned';

    public function handle(RunIntegrationSync $sync): int
    {
        $integrations = Integration::query()
            ->where('status', Integration::STATUS_CONNECTED)
            ->when($this->option('project'), fn ($q, $id) => $q->where('project_id', $id))
            ->when($this->option('provider'), fn ($q, $p) => $q->where('provider', $p))
            ->with('project')
            ->get();

        if ($integrations->isEmpty()) {
            $this->warn('No connected integrations matched. Connect one in the app first.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = false;

        foreach ($integrations as $integration) {
            if ($this->option('queue')) {
                SyncIntegration::dispatch($integration->project, $integration->provider);
                $rows[] = [$integration->project->name, $integration->provider, 'queued', '—'];

                continue;
            }

            // Same action the queued job runs, so logging and events match.
            $result = $sync->handle($integration->project, $integration->provider);

            $failed = $failed || ! $result->ok;

            $rows[] = [
                $integration->project->name,
                $integration->provider,
                $result->ok ? '<info>ok</info>' : '<error>failed</error>',
                $result->ok ? $result->metricsRecorded.' metrics' : $result->error,
            ];
        }

        $this->table(['Project', 'Provider', 'Result', 'Detail'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
