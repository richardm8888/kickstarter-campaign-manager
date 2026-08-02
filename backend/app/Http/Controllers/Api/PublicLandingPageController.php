<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unauthenticated endpoints serving the published landing page
 * and its conversion actions (email capture, £1 VIP upgrade).
 */
class PublicLandingPageController extends Controller
{
    public function show(string $slug): JsonResponse
    {
        $page = LandingPage::query()
            ->where('slug', $slug)
            ->where('published', true)
            ->with(['sections' => fn ($q) => $q->where('enabled', true)])
            ->firstOrFail();

        return response()->json([
            'landing_page' => [
                'slug' => $page->slug,
                'template' => $page->template,
                'theme' => $page->theme,
                'project_name' => $page->project->name,
                'sections' => $page->sections,
            ],
        ]);
    }

    public function subscribe(Request $request, string $slug, MetricRecorder $metrics): JsonResponse
    {
        $page = LandingPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $subscriber = $page->project->subscribers()->firstOrCreate(
            ['email' => strtolower($validated['email'])],
            ['source' => 'landing_page'],
        );

        if ($subscriber->wasRecentlyCreated) {
            $metrics->record($page->project, 'landing_page', 'signups', 1);
        }

        return response()->json(['message' => 'You are on the list!'], 201);
    }

    public function upgradeToVip(Request $request, string $slug, MetricRecorder $metrics): JsonResponse
    {
        $page = LandingPage::query()->where('slug', $slug)->where('published', true)->firstOrFail();

        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);

        $subscriber = $page->project->subscribers()->firstOrCreate(
            ['email' => strtolower($validated['email'])],
            ['source' => 'landing_page'],
        );

        if (! $subscriber->is_vip) {
            $subscriber->update(['is_vip' => true]);
            $metrics->record($page->project, 'landing_page', 'vip_upgrades', 1);
        }

        return response()->json(['message' => 'Welcome, VIP!']);
    }
}
