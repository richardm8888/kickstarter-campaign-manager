<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\Providers\MetaIntegration;
use App\Jobs\SyncIntegration;
use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Ads\EventSetupStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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

    /** Live list of conversion types Meta reports, for mapping custom events. */
    public function actionTypes(Project $project, IntegrationManager $integrations): JsonResponse
    {
        $this->authorize('view', $project);

        $integration = $integrations->for($project, 'meta');

        if (! $integration instanceof MetaIntegration) {
            return response()->json(['action_types' => [], 'mapping' => []]);
        }

        $settings = $project->integrations()->where('provider', 'meta')->first()?->settings ?? [];

        try {
            $types = $integration->discoverActionTypes();
        } catch (Throwable $e) {
            return response()->json([
                'action_types' => [],
                'mapping' => [],
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'action_types' => $types,
            'mapping' => [
                'lead' => $settings['lead_actions'] ?? MetaIntegration::LEAD_ACTIONS,
                'view_content' => $settings['view_content_actions'] ?? MetaIntegration::VIEW_CONTENT_ACTIONS,
            ],
        ]);
    }

    public function mapActions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'lead_actions' => ['sometimes', 'array'],
            'lead_actions.*' => ['string', 'max:255'],
            'view_content_actions' => ['sometimes', 'array'],
            'view_content_actions.*' => ['string', 'max:255'],
        ]);

        $integration = $project->integrations()->where('provider', 'meta')->firstOrFail();

        $integration->update(['settings' => [...$integration->settings ?? [], ...$validated]]);

        // Re-read the window so the change applies to existing days.
        SyncIntegration::dispatch($project, 'meta');

        return response()->json(['message' => 'Mapping saved. Re-syncing the last 14 days.']);
    }
}
