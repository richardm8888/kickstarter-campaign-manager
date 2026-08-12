<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\PageAudit\KickstarterPageAnalyser;
use App\Services\PageAudit\LandingPageAnalyser;
use App\Services\PageAudit\PageAnalyser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class PageAnalysisController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'page_type' => ['sometimes', 'in:landing,kickstarter'],
        ]);

        return response()->json([
            'analyses' => $project->landingPageAnalyses()
                ->when($validated['page_type'] ?? null, fn ($q, $type) => $q->where('page_type', $type))
                ->latest()
                ->take(10)
                ->get(),
        ]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:255'],
            'page_type' => ['sometimes', 'in:landing,kickstarter'],
            // Remember this as the project's page of that kind when asked.
            'remember' => ['sometimes', 'boolean'],
        ]);

        $type = $validated['page_type'] ?? 'landing';

        try {
            $analysis = $this->analyserFor($type)->analyse($project, $validated['url']);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        if ($validated['remember'] ?? false) {
            $project->update([
                $type === 'kickstarter' ? 'kickstarter_url' : 'external_landing_url' => $analysis->url,
            ]);
        }

        return response()->json(['analysis' => $analysis], 201);
    }

    private function analyserFor(string $type): PageAnalyser
    {
        return app($type === 'kickstarter' ? KickstarterPageAnalyser::class : LandingPageAnalyser::class);
    }
}
