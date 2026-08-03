<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Kickstarter\KickstarterFollowers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled hourly: re-reads the follower count from every linked
 * Kickstarter pre-launch page.
 *
 * Followers are the strongest pre-launch signal a creator has and there is
 * no API for them, so the page is polled. Hourly is frequent enough to
 * chart growth against ad spend and gentle enough not to hammer
 * Kickstarter.
 */
class SyncKickstarterFollowers implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function handle(KickstarterFollowers $followers): void
    {
        Project::query()
            ->whereNotNull('kickstarter_url')
            ->each(function (Project $project) use ($followers) {
                try {
                    $followers->sync($project);
                } catch (Throwable $e) {
                    // One unreadable page must not stop the rest.
                    Log::warning('Kickstarter follower sync failed for project', [
                        'project_id' => $project->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
