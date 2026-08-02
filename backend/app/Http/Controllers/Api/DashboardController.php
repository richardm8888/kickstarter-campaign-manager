<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\Funnel\FunnelBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use AuthorizesRequests;

    public function show(Project $project, DashboardMetrics $metrics, FunnelBuilder $funnel): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'cards' => $metrics->cards($project),
            'funnel' => $funnel->build($project),
            'currency' => $project->currency,
        ]);
    }
}
