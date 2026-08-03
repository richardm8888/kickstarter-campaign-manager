<?php

namespace App\Http\Controllers\Api;

use App\Forecasting\BackerRates;
use App\Forecasting\ForecastEngine;
use App\Forecasting\FunnelRating;
use App\Forecasting\LaunchPlan;
use App\Http\Controllers\Controller;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The forecast asks for one thing: planned ad spend. Cost per click and
 * conversion rates are measured from the creator's own ads, and the
 * backer rate — which nobody can know before launching — is shown as a
 * range rather than demanded as an input.
 */
class ForecastController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly ForecastEngine $engine,
        private readonly FunnelRating $ratings,
        private readonly LaunchPlan $plan,
    ) {}

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'planned_ad_spend' => ['sometimes', 'integer', 'min:0', 'max:100000000'],
        ]);

        $plannedAdSpend = $validated['planned_ad_spend'] ?? null;
        $input = $this->engine->inputFor($project, $plannedAdSpend);
        $recommended = $this->engine->recommendedBudget($project);

        $cpcMeasured = $this->engine->hasMeasuredCpc($project);
        $conversionMeasured = $this->engine->hasMeasuredConversion($project);

        return response()->json([
            'scenarios' => $this->engine->scenarios($project, $plannedAdSpend),
            'requirements' => $this->engine->requirements($project, $plannedAdSpend),
            'ratings' => [
                'cpc' => $this->ratings->cpc($input->cpc),
                'conversion' => $this->ratings->conversion($input->visitorToSubscriberRate),
            ],
            'focus' => $this->ratings->focus(
                $input->cpc,
                $input->visitorToSubscriberRate,
                $cpcMeasured && $conversionMeasured,
            ),
            'plan' => $this->plan->build($project, $plannedAdSpend),
            'audience_value' => $this->engine->audienceValue($project),
            'planning_rates' => BackerRates::planning(),
            'recommended_budget' => $recommended,
            'measured' => [
                'email_subscribers' => $input->emailSubscribers(),
                'vip_count' => $input->vipCount(),
                'followers' => $input->followers(),
                'marginal_backer_rate' => round($input->marginalBackerRate(), 4),
                'ad_mix' => $input->adMix,
                'planned_ad_spend' => $input->plannedAdSpend,
                'cpc' => $input->cpc,
                'visitor_to_subscriber_rate' => $input->visitorToSubscriberRate,
                'average_pledge' => $input->averagePledge,
                'funding_goal' => $input->fundingGoal,
                // Which figures came from this account rather than benchmarks.
                'cpc_measured' => $cpcMeasured,
                'conversion_measured' => $conversionMeasured,
            ],
            'confidence' => $this->engine->forecast($input)->confidence,
        ]);
    }

    /** Remembers the planned spend so the screen reopens where it was left. */
    public function saveAssumptions(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'planned_ad_spend' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        $project->update([
            'forecast_assumptions' => ['planned_ad_spend' => $validated['planned_ad_spend']],
        ]);

        return response()->json([
            'planned_ad_spend' => $validated['planned_ad_spend'],
            'message' => 'Saved as your planned budget.',
        ]);
    }
}
