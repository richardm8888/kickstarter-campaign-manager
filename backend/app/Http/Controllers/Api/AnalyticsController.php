<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Services\Analytics\MetricCatalog;
use App\Services\Analytics\MetricSeries;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    use AuthorizesRequests;

    private const CATEGORIES = [
        'traffic' => [
            ['metric' => 'sessions', 'label' => 'Sessions'],
            ['metric' => 'users', 'label' => 'Users'],
            ['metric' => 'pageviews', 'label' => 'Page views'],
        ],
        'ads' => [
            ['metric' => 'spend', 'label' => 'Spend'],
            ['metric' => 'cpc', 'label' => 'CPC'],
            ['metric' => 'cpm', 'label' => 'CPM'],
            ['metric' => 'ctr', 'label' => 'CTR'],
            ['metric' => 'clicks', 'label' => 'Clicks'],
        ],
        'email' => [
            ['metric' => 'email_subscribers', 'label' => 'Subscribers'],
            ['metric' => 'email_opens', 'label' => 'Opens'],
            ['metric' => 'email_clicks', 'label' => 'Clicks'],
            ['metric' => 'email_unsubscribes', 'label' => 'Unsubscribes'],
            ['metric' => 'email_form_open_rate', 'label' => 'Open rate — Meta form leads'],
            ['metric' => 'email_other_open_rate', 'label' => 'Open rate — everyone else'],
            ['metric' => 'email_form_click_rate', 'label' => 'Click rate — Meta form leads'],
            ['metric' => 'email_other_click_rate', 'label' => 'Click rate — everyone else'],
        ],
        'revenue' => [
            ['metric' => 'revenue', 'label' => 'Revenue'],
            ['metric' => 'payments', 'label' => 'Payments'],
        ],
    ];

    public function show(
        Request $request,
        Project $project,
        MetricSeries $series,
        MetricCatalog $catalog,
    ): JsonResponse {
        $this->authorize('view', $project);

        $validated = $request->validate([
            'category' => ['required', Rule::in(array_keys(self::CATEGORIES))],
            'days' => ['sometimes', 'integer', 'min:7', 'max:90'],
        ]);

        $days = (int) ($validated['days'] ?? 30);

        $metrics = array_map(fn (array $definition) => [
            'metric' => $definition['metric'],
            'label' => $definition['label'],
            'series' => $series->daily($project, $definition['metric'], $days),
            'total' => $series->sum($project, $definition['metric'], $days),
            'latest' => $series->latest($project, $definition['metric']),
            'change' => $series->changePercent($project, $definition['metric']),
            'aggregation' => $catalog->aggregation($definition['metric']),
        ], self::CATEGORIES[$validated['category']]);

        return response()->json(['category' => $validated['category'], 'metrics' => $metrics]);
    }
}
