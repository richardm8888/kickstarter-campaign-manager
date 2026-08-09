<?php

namespace App\Http\Controllers\Api;

use App\Daily\DailyBrief;
use App\Http\Controllers\Controller;
use App\Models\DailyTask;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyTaskController extends Controller
{
    use AuthorizesRequests;

    /**
     * Today's brief, regenerated on read.
     *
     * Regenerating rather than serving what the scheduler last stored
     * means the list reflects the numbers as they are now — a creator who
     * pauses an ad and reloads should not be told to pause it again. The
     * detectors are all reads against data already in the database, so
     * this is cheap enough to do on every visit.
     */
    public function index(Request $request, Project $project, DailyBrief $brief): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($brief->build($project));
    }

    /**
     * What was raised before today, and what happened to it.
     *
     * Kept visible because a list you cannot look back at teaches you
     * nothing: seeing that last week's email push was followed by a jump
     * in followers is how the next decision gets easier.
     */
    public function history(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'tasks' => $project->dailyTasks()
                ->where('for_date', '<', now()->toDateString())
                ->orderByDesc('for_date')
                ->orderByDesc('score')
                ->take(30)
                ->get(),
        ]);
    }

    public function update(Request $request, Project $project, DailyTask $task): JsonResponse
    {
        $this->authorize('update', $project);

        abort_unless($task->project_id === $project->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'in:open,done,dismissed'],
        ]);

        $task->update([
            'status' => $validated['status'],
            // Stamped only on completion, because the cooldown that stops
            // a finished job coming back tomorrow is measured from it.
            'completed_at' => $validated['status'] === DailyTask::DONE ? now() : null,
        ]);

        return response()->json(['task' => $task]);
    }
}
