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
        {--days=30 : How far back to look}
        {--resync : Send contacts to the email provider again, even if already sent}';

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
                $result = $import->handle(
                    $project,
                    (int) $this->option('days'),
                    (bool) $this->option('resync'),
                );

                $this->line(sprintf(
                    '%s: %d leads found in Meta — %d new, %d forwarded to email, %d already forwarded, %d skipped (no email address)',
                    $project->name,
                    $result['found'],
                    $result['imported'],
                    $result['forwarded'],
                    $result['already_forwarded'],
                    $result['skipped'],
                ));

                if ($result['found'] === 0) {
                    $this->line('  No leads returned. Check the token has leads_retrieval and the system user has Leads Access on the Page.');
                } elseif ($result['already_forwarded'] > 0 && $result['forwarded'] === 0) {
                    $this->line('  Nothing new to send. Use --resync to push them again (e.g. after adding fields in MailerLite).');
                }
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
