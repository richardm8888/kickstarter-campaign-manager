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

        // Not finding a count is the normal outcome, not a failure: a
        // pre-launch page carries no follower count at all, only a "Notify
        // me on launch" button. Reporting it as an error made a scheduled
        // run look broken every hour and taught people to ignore it.
        if ($unread > 0) {
            $this->components->info(
                "{$unread} page(s) published no follower count. Kickstarter shows that "
                .'number in your creator dashboard only — enter it in Settings to track it.',
            );
        }

        return self::SUCCESS;
    }
}
