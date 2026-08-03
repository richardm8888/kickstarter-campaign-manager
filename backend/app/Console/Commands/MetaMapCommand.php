<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Maps a Meta action type — typically a custom conversion — onto one of
 * our events, so ads can be judged on the creator's own signup event.
 */
class MetaMapCommand extends Command
{
    protected $signature = 'meta:map
        {--project= : Project id}
        {--lead=* : Action type(s) that mean a signup}
        {--view-content=* : Action type(s) that mean a page view}
        {--reset : Restore the built-in defaults}';

    protected $description = 'Map Meta conversion action types onto signups and page views';

    public function handle(): int
    {
        $project = $this->option('project')
            ? Project::find($this->option('project'))
            : Project::first();

        if (! $project) {
            $this->error('No project found. Pass --project=<id>.');

            return self::FAILURE;
        }

        $integration = $project->integrations()->where('provider', 'meta')->first();

        if (! $integration instanceof Integration) {
            $this->error('Meta is not connected for this project.');

            return self::FAILURE;
        }

        $settings = $integration->settings ?? [];

        if ($this->option('reset')) {
            unset($settings['lead_actions'], $settings['view_content_actions']);
            $this->info('Restored the built-in action mapping.');
        }

        if ($leads = $this->option('lead')) {
            $settings['lead_actions'] = $leads;
            $this->info('Signups will be counted from: '.implode(', ', $leads));
        }

        if ($views = $this->option('view-content')) {
            $settings['view_content_actions'] = $views;
            $this->info('Page views will be counted from: '.implode(', ', $views));
        }

        $integration->update(['settings' => $settings]);

        $this->newLine();
        $this->line('Re-sync to apply it to the last 14 days:');
        $this->line("  php artisan integrations:sync --project={$project->id} --provider=meta");

        return self::SUCCESS;
    }
}
