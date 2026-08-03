<?php

namespace App\Console\Commands;

use App\Integrations\IntegrationManager;
use App\Integrations\Providers\MetaIntegration;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Shows every conversion type Meta reports for an account, and which of
 * them this project currently counts. The fastest way to find out why
 * signups are not appearing — usually a custom conversion with a name we
 * do not recognise.
 */
class MetaActionsCommand extends Command
{
    protected $signature = 'meta:actions {--project= : Project id (defaults to the only one)}';

    protected $description = 'List the conversion action types Meta reports, and how they map';

    public function handle(IntegrationManager $integrations): int
    {
        $project = $this->option('project')
            ? Project::find($this->option('project'))
            : Project::first();

        if (! $project) {
            $this->error('No project found. Pass --project=<id>.');

            return self::FAILURE;
        }

        $integration = $integrations->for($project, 'meta');

        if (! $integration instanceof MetaIntegration) {
            $this->error('Meta integration unavailable.');

            return self::FAILURE;
        }

        $this->line("Project: {$project->name}");

        $types = $integration->discoverActionTypes();

        if ($types === []) {
            $this->warn('Meta returned no conversion actions at all for the last 14 days.');
            $this->line('That means either no conversions have been attributed yet, or the pixel is not firing.');

            return self::SUCCESS;
        }

        $settings = $project->integrations()->where('provider', 'meta')->first()?->settings ?? [];
        $leadTypes = $settings['lead_actions'] ?? MetaIntegration::LEAD_ACTIONS;
        $viewTypes = $settings['view_content_actions'] ?? MetaIntegration::VIEW_CONTENT_ACTIONS;

        $rows = array_map(fn (array $type) => [
            $type['action_type'],
            (int) $type['total'],
            match (true) {
                in_array($type['action_type'], $leadTypes, true) => 'counted as signups',
                in_array($type['action_type'], $viewTypes, true) => 'counted as page views',
                default => '<comment>ignored</comment>',
            },
        ], $types);

        $this->table(['Meta action type', 'Total (14d)', 'How we use it'], $rows);

        $ignored = array_filter($rows, fn (array $row) => str_contains($row[2], 'ignored'));

        if ($ignored !== []) {
            $this->newLine();
            $this->line('Some conversions are being ignored. If one of them is your signup event, map it:');
            $this->line('  php artisan meta:map --project='.$project->id.' --lead="<action type>"');
        }

        return self::SUCCESS;
    }
}
