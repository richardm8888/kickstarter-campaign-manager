<?php

namespace App\Jobs;

use App\Daily\DailyBrief;
use App\Models\Project;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes each project's list once a day.
 *
 * Reading the brief regenerates it, so this is not what makes the page
 * correct — it is what gives the history something to record. Without it,
 * a week where nobody logged in leaves no trace, and the record of what
 * was recommended against what happened next is the part that makes the
 * advice worth trusting later.
 */
class GenerateDailyBriefs implements ShouldQueue
{
    use Queueable;

    public function handle(DailyBrief $brief): void
    {
        Project::query()->eachById(function (Project $project) use ($brief) {
            try {
                $brief->generate($project);
            } catch (Throwable $e) {
                // One project's missing data must not stop everyone else's
                // list being written.
                Log::warning('Daily brief generation failed', [
                    'project_id' => $project->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        });
    }
}
