<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateProject;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Rules\KickstarterUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'projects' => $request->user()->projects()->latest()->get(),
        ]);
    }

    public function store(Request $request, CreateProject $action): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'external_landing_url' => ['nullable', 'url', 'max:255'],
            'kickstarter_url' => ['nullable', 'url', 'max:255', new KickstarterUrl],
            'currency' => ['nullable', 'string', 'size:3'],
            'funding_goal' => ['nullable', 'integer', 'min:0'],
            'average_pledge' => ['nullable', 'integer', 'min:0'],
            'guaranteed_backers' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'launch_date' => ['nullable', 'date'],
        ]);

        $project = $action->handle($request->user(), $validated);

        return response()->json(['project' => $project->load('landingPage.sections')], 201);
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'project' => $project->load('landingPage.sections', 'integrations'),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'external_landing_url' => ['nullable', 'url', 'max:255'],
            'kickstarter_url' => ['nullable', 'url', 'max:255', new KickstarterUrl],
            'currency' => ['sometimes', 'string', 'size:3'],
            'funding_goal' => ['sometimes', 'integer', 'min:0'],
            'average_pledge' => ['sometimes', 'integer', 'min:0'],
            'guaranteed_backers' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'launch_date' => ['nullable', 'date'],
        ]);

        $project->update($validated);

        return response()->json(['project' => $project]);
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return response()->json(['message' => 'Project deleted.']);
    }
}
