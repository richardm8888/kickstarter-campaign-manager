<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Jobs\SyncIntegration;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly IntegrationManager $integrations) {}

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'integrations' => array_map(
                fn ($integration) => $integration->status(),
                $this->integrations->all($project),
            ),
        ]);
    }

    public function connect(Request $request, Project $project, string $provider): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'credentials' => ['required', 'array'],
            'credentials.*' => ['string'],
        ]);

        $this->validateProvider($provider);

        $integration = $this->integrations->for($project, $provider);
        $integration->connect($validated['credentials']);

        SyncIntegration::dispatch($project, $provider);

        return response()->json(['integration' => $integration->status()]);
    }

    public function disconnect(Project $project, string $provider): JsonResponse
    {
        $this->authorize('update', $project);
        $this->validateProvider($provider);

        $integration = $this->integrations->for($project, $provider);
        $integration->disconnect();

        return response()->json(['integration' => $integration->status()]);
    }

    public function sync(Project $project, string $provider): JsonResponse
    {
        $this->authorize('update', $project);
        $this->validateProvider($provider);

        SyncIntegration::dispatch($project, $provider);

        return response()->json(['message' => 'Sync queued.'], 202);
    }

    private function validateProvider(string $provider): void
    {
        validator(['provider' => $provider], [
            'provider' => [Rule::in($this->integrations->providers())],
        ])->validate();
    }
}
