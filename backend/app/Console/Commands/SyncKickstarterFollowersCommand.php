<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Kickstarter\KickstarterFollowers;
use Illuminate\Console\Command;

class SyncKickstarterFollowersCommand extends Command
{
    protected $signature = 'kickstarter:followers {--project= : Only this project id}';

    protected $description = 'Read follower counts from linked Kickstarter pre-launch pages';

    public function handle(KickstarterFollowers $followers): int
    {
        $projects = Project::query()
            ->whereNotNull('kickstarter_url')
            ->when($this->option('project'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        if ($projects->isEmpty()) {
            $this->warn('No projects have a Kickstarter page linked.');

            return self::SUCCESS;
        }

        $unread = 0;

        foreach ($projects as $project) {
            $count = $followers->sync($project);

            if ($count === null) {
                $unread++;
                $this->line("  <fg=gray>{$project->name}: no count on the page</>");

                continue;
            }

            $this->info("{$project->name}: {$count} followers");
        }

        // A page that shows no count is not a failed run — a project can
        // be too new, or Kickstarter can change its markup. Either way the
        // creator can record the figure by hand, so this stays a notice
        // rather than an hourly red error nobody reads by week two.
        if ($unread > 0) {
            $this->components->info(
                "{$unread} page(s) showed no follower count. If yours does show one, run "
                .'kickstarter:inspect against it — the pattern may need updating. '
                .'Meanwhile it can be entered by hand in Settings.',
            );
        }

        return self::SUCCESS;
    }
}
