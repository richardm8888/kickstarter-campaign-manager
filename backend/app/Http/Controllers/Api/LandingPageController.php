<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingPageSection;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LandingPageController extends Controller
{
    use AuthorizesRequests;

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return response()->json([
            'landing_page' => $project->landingPage()->with('sections')->firstOrFail(),
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $page = $project->landingPage()->firstOrFail();

        $validated = $request->validate([
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('landing_pages')->ignore($page->id)],
            'published' => ['sometimes', 'boolean'],
            'theme' => ['sometimes', 'array'],
        ]);

        $page->update($validated);

        return response()->json(['landing_page' => $page->load('sections')]);
    }

    /**
     * Replace section content/order in one call. Section types are fixed
     * by the template — configuration over freedom.
     */
    public function updateSections(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $page = $project->landingPage()->firstOrFail();

        $validated = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.enabled' => ['sometimes', 'boolean'],
            'sections.*.position' => ['sometimes', 'integer', 'min:0'],
            'sections.*.content' => ['sometimes', 'array'],
        ]);

        foreach ($validated['sections'] as $data) {
            $section = $page->sections()->whereKey($data['id'])->first();

            if ($section instanceof LandingPageSection) {
                $section->update(collect($data)->only(['enabled', 'position', 'content'])->all());
            }
        }

        return response()->json(['landing_page' => $page->load('sections')]);
    }
}
