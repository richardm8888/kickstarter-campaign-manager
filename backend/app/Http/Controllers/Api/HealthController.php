<?php

namespace App\Http\Controllers\Api;

use App\AI\CampaignAuditor;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    use AuthorizesRequests;

    public function show(Project $project, CampaignAuditor $auditor): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json($auditor->audit($project));
    }
}
