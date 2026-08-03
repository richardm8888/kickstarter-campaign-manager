<?php

namespace App\Console\Commands;

use App\Actions\ImportMetaLeads;
use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

class ImportLeadsCommand extends Command
{
    protected $signature = 'meta:import-leads
        {--project= : Project id (defaults to all)}
        {--days=30 : How far back to look}';

    protected $description = 'Import Meta Instant Form leads and forward them to the email provider';

    public function handle(ImportMetaLeads $import): int
    {
        $projects = $this->option('project')
            ? Project::where('id', $this->option('project'))->get()
            : Project::whereHas('integrations', fn ($q) => $q->where('provider', 'meta'))->get();

        if ($projects->isEmpty()) {
            $this->warn('No project with Meta connected.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($projects as $project) {
            try {
                $result = $import->handle($project, (int) $this->option('days'));

                $this->line(sprintf(
                    '%s: %d imported, %d forwarded to email, %d skipped (no email address)',
                    $project->name,
                    $result['imported'],
                    $result['forwarded'],
                    $result['skipped'],
                ));
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$project->name}: {$e->getMessage()}");

                if (str_contains($e->getMessage(), 'leads_retrieval')) {
                    $this->line('  Your Meta token needs the leads_retrieval permission to read form leads.');
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
