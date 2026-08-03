<?php

namespace App\Console\Commands;

use App\Actions\ImportStripeVips;
use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

class ImportStripeVipsCommand extends Command
{
    protected $signature = 'stripe:import-vips
        {--project= : Project id (defaults to all)}
        {--days=30 : How far back to look}
        {--resync : Push contacts to the email provider again}';

    protected $description = 'Turn Stripe purchases of the configured VIP products into VIP subscribers';

    public function handle(ImportStripeVips $import): int
    {
        $projects = $this->option('project')
            ? Project::where('id', $this->option('project'))->get()
            : Project::whereHas('integrations', fn ($q) => $q->where('provider', 'stripe'))->get();

        if ($projects->isEmpty()) {
            $this->warn('No project with Stripe connected.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($projects as $project) {
            try {
                $result = $import->handle(
                    $project,
                    (int) $this->option('days'),
                    (bool) $this->option('resync'),
                );

                $this->line(sprintf(
                    '%s: %d matching purchases — %d new VIPs, %d added to the email group, %d already VIP',
                    $project->name,
                    $result['found'],
                    $result['imported'],
                    $result['forwarded'],
                    $result['already_vip'],
                ));

                if ($result['found'] === 0) {
                    $this->line('  Nothing matched. Check the VIP products are selected on the Integrations screen.');
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$project->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
