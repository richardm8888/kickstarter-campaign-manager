<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Ads\EventSetupStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

}
