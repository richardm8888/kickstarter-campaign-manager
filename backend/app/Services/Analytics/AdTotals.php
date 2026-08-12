<?php

namespace App\Services\Analytics;

use App\Models\Project;
use App\Recommendations\AdType;

/**
 * Totals for the per-ad metric series: per ad, per ad type, or whole
 * account.
 *
 * The collapse of repeated observations lives in SegmentTotals, which
 * the analytics breakdowns share — so per-ad figures and per-region
 * figures cannot drift into disagreeing about what a day was. What is
 * here is the ad vocabulary on top of it.
 */
class AdTotals
{
    public function __construct(private readonly SegmentTotals $segments) {}

    /**
     * One row per ad: its latest dimensions and the window's totals.
     *
     * @param  list<string>  $metrics
     * @return list<array{dimensions: array<string, mixed>, totals: array<string, float>}>
     */
    public function perAd(Project $project, array $metrics, int $days = 30): array
    {
        return $this->segments->get($project, $metrics, 'ad_id', $days, 'meta');
    }

    /**
     * Totals keyed by ad type then metric. Only types with data appear.
     *
     * @param  list<string>  $metrics
     * @return array<string, array<string, float>>
     */
    public function byType(Project $project, array $metrics, int $days = 30): array
    {
        $totals = [];

        foreach ($this->perAd($project, $metrics, $days) as $ad) {
            $type = (AdType::tryFrom($ad['dimensions']['ad_type'] ?? '') ?? AdType::Unknown)->value;
            $totals[$type] ??= array_fill_keys($metrics, 0.0);

            foreach ($ad['totals'] as $metric => $value) {
                $totals[$type][$metric] += $value;
            }
        }

        return $totals;
    }

    /**
     * Totals for the whole account, keyed by metric.
     *
     * @param  list<string>  $metrics
     * @return array<string, float>
     */
    public function total(Project $project, array $metrics, int $days = 30): array
    {
        $totals = array_fill_keys($metrics, 0.0);

        foreach ($this->perAd($project, $metrics, $days) as $ad) {
            foreach ($ad['totals'] as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return $totals;
    }
}
