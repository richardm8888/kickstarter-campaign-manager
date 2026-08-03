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

        $failed = 0;

        foreach ($projects as $project) {
            $count = $followers->sync($project);

            if ($count === null) {
                $failed++;
                $this->error("{$project->name}: could not read a follower count from {$project->kickstarter_url}");

                continue;
            }

            $this->info("{$project->name}: {$count} followers");
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
