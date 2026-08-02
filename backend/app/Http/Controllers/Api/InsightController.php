<?php

namespace App\Http\Controllers\Api;

use App\AI\InsightGenerator;
use App\AI\RecommendationGenerator;
use App\Http\Controllers\Controller;
use App\Models\Insight;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class InsightController extends Controller
{
    use AuthorizesRequests;

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'insights' => $project->insights()->latest()->take(50)->get(),
        ]);
    }

    public function generate(
        Project $project,
        InsightGenerator $insights,
        RecommendationGenerator $recommendations,
    ): JsonResponse {
        $this->authorize('update', $project);

        $created = [
            ...$insights->generate($project),
            ...$recommendations->generate($project),
        ];

        return response()->json(['created' => $created], 201);
    }

    public function acknowledge(Project $project, Insight $insight): JsonResponse
    {
        $this->authorize('update', $project);

        abort_unless($insight->project_id === $project->id, 404);

        $insight->update(['acknowledged_at' => now()]);

        return response()->json(['insight' => $insight]);
    }
}
