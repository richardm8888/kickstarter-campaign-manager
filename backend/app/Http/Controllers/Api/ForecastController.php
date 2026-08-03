<?php

namespace App\Http\Controllers\Api;

use App\Forecasting\ForecastEngine;
use App\Forecasting\ForecastInput;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly ForecastEngine $engine) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'planned_ad_spend' => ['sometimes', 'integer', 'min:0'],
        ]);

        $input = $this->engine->inputFor($project, $validated['planned_ad_spend'] ?? null);

        return response()->json([
            'forecast' => $this->engine->forecast($input)->toArray(),
            'input' => [
                'email_subscribers' => $input->emailSubscribers,
                'vip_count' => $input->vipCount,
                'planned_ad_spend' => $input->plannedAdSpend,
                'cpc' => $input->cpc,
                'visitor_to_subscriber_rate' => $input->visitorToSubscriberRate,
                'subscriber_to_backer_rate' => $input->subscriberToBackerRate,
                'vip_to_backer_rate' => $input->vipToBackerRate,
                'average_pledge' => $input->averagePledge,
                'funding_goal' => $input->fundingGoal,
            ],
        ]);
    }

    /** Stores the creator's assumptions so the screen reopens where they left it. */
    public function saveAssumptions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'planned_ad_spend' => ['sometimes', 'integer', 'min:0'],
            'cpc' => ['sometimes', 'numeric', 'min:0.01'],
            'visitor_to_subscriber_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'subscriber_to_backer_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'vip_to_backer_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'average_pledge' => ['sometimes', 'integer', 'min:0'],
        ]);

        $project->update([
            'forecast_assumptions' => [...$project->forecast_assumptions ?? [], ...$validated],
        ]);

        return response()->json([
            'assumptions' => $project->forecast_assumptions,
            'message' => 'Saved. These will be used next time.',
        ]);
    }

    /** What-if forecast with user-supplied assumptions overriding observed data. */
    public function preview(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'email_subscribers' => ['sometimes', 'integer', 'min:0'],
            'vip_count' => ['sometimes', 'integer', 'min:0'],
            'planned_ad_spend' => ['sometimes', 'integer', 'min:0'],
            'cpc' => ['sometimes', 'numeric', 'min:0.01'],
            'visitor_to_subscriber_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'subscriber_to_backer_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'vip_to_backer_rate' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'average_pledge' => ['sometimes', 'integer', 'min:0'],
        ]);

        $base = $this->engine->inputFor($project, $validated['planned_ad_spend'] ?? null);

        $input = new ForecastInput(
            emailSubscribers: $validated['email_subscribers'] ?? $base->emailSubscribers,
            vipCount: $validated['vip_count'] ?? $base->vipCount,
            plannedAdSpend: $validated['planned_ad_spend'] ?? $base->plannedAdSpend,
            cpc: (float) ($validated['cpc'] ?? $base->cpc),
            visitorToSubscriberRate: (float) ($validated['visitor_to_subscriber_rate'] ?? $base->visitorToSubscriberRate),
            subscriberToBackerRate: (float) ($validated['subscriber_to_backer_rate'] ?? $base->subscriberToBackerRate),
            vipToBackerRate: (float) ($validated['vip_to_backer_rate'] ?? $base->vipToBackerRate),
            averagePledge: $validated['average_pledge'] ?? $base->averagePledge,
            fundingGoal: $base->fundingGoal,
            dataCompleteness: $base->dataCompleteness,
        );

        return response()->json(['forecast' => $this->engine->forecast($input)->toArray()]);
    }
}
