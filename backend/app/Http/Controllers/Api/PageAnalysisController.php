<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\LandingPage\LandingPageAnalyser;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class PageAnalysisController extends Controller
{
    use AuthorizesRequests;

    public function index(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'analyses' => $project->landingPageAnalyses()->latest()->take(10)->get(),
        ]);
    }

    public function store(Request $request, Project $project, LandingPageAnalyser $analyser): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'url' => ['required', 'url', 'max:255'],
            // Remember this as the project's landing page when asked.
            'remember' => ['sometimes', 'boolean'],
        ]);

        try {
            $analysis = $analyser->analyse($project, $validated['url']);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw ValidationException::withMessages(['url' => [$e->getMessage()]]);
        }

        if ($validated['remember'] ?? false) {
            $project->update(['external_landing_url' => $analysis->url]);
        }

        return response()->json(['analysis' => $analysis], 201);
    }
}
