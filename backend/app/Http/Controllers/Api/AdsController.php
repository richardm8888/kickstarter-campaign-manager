<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Ads\EventSetupStatus;
use App\Services\Ads\MetaPixelProbe;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AdsController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Project $project, AdPerformanceAnalyser $analyser): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:3', 'max:90'],
        ]);

        return response()->json($analyser->analyse($project, (int) ($validated['days'] ?? 14)));
    }

    public function events(Project $project, EventSetupStatus $status): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($status->for($project));
    }

    /**
     * Asks Meta what pixel event data it will return.
     *
     * A POST, and gated on `update`, because it spends the project's Meta
     * token against a rate-limited API — a read-shaped GET would invite
     * being called on every page load.
     */
    public function pixelProbe(Project $project, MetaPixelProbe $probe): JsonResponse
    {
        $this->authorize('update', $project);

        try {
            return response()->json($probe->run($project));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
