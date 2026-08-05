<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Records a follower count the creator read off their own dashboard.
 *
 * Kickstarter does not publish this number. It is not hidden behind
 * awkward markup — a pre-launch page carries no follower count at all,
 * only a "Notify me on launch" button, and the count lives in the
 * creator's dashboard where only they can see it.
 *
 * Followers are the highest-converting audience a pre-launch campaign
 * has, so a number typed in weekly beats an automated one that never
 * arrives. The scraper still runs in case a page ever exposes it; this is
 * the path that always works.
 */
class KickstarterFollowerController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Project $project, MetricRecorder $recorder): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:0', 'max:10000000'],
            // Backfilling last week's reading should land on last week.
            'recorded_at' => ['sometimes', 'date', 'before_or_equal:now'],
        ]);

        $snapshot = $recorder->record(
            $project,
            'manual',
            'ks_followers',
            $validated['count'],
            $validated['recorded_at'] ?? null,
        );

        return response()->json([
            'count' => (int) $snapshot->value,
            'recorded_at' => $snapshot->recorded_at,
        ], 201);
    }
}
