<?php

namespace App\Services\Funnel;

use App\Forecasting\BackerRates;
use App\Forecasting\ForecastEngine;
use App\Models\Project;
use App\Recommendations\AdType;
use App\Services\Analytics\AdTotals;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;

/**
 * The pre-launch funnel, from money spent to backers projected:
 *
 *   Impressions → Ad clicks → { landing page views | form views }
 *               → Signups & followers → VIPs → Projected backers
 *
 * Clicks branch because the three recommended ads send people to different
 * places: a landing page or Kickstarter page loads in a browser, while an
 * instant form opens inside Meta. Collapsing them would hide which route is
 * actually producing contacts.
 *
 * The ad steps cover a rolling window; the audience steps are totals, since
 * a list is an asset that accumulates rather than a flow. Steps only carry
 * a conversion percentage where it is measured against a like-for-like
 * figure — a percentage between two different bases is worse than none.
 */
class FunnelBuilder
{
    private const WINDOW_DAYS = 30;

    private const WINDOW = 'window';

    private const TOTAL = 'total';

    private const AD_METRICS = [
        'ad_impressions',
        'ad_spend',
        'ad_clicks',
        'ad_landing_page_views',
        'ad_form_views',
        'ad_leads',
        'ad_follows',
    ];

    public function __construct(
        private readonly MetricSeries $series,
        private readonly ForecastEngine $forecasts,
        private readonly AudienceSize $audience,
        private readonly AdTotals $ads,
    ) {}

    public function build(Project $project): array
    {
        $byType = $this->ads->byType($project, self::AD_METRICS, self::WINDOW_DAYS);
        $totals = $this->sumTypes($byType);

        $impressions = (int) $this->preferAccount($project, $totals['ad_impressions'], 'impressions');
        $clicks = (int) $this->preferAccount($project, $totals['ad_clicks'], 'clicks');
        $spend = $this->preferAccount($project, $totals['ad_spend'], 'spend');

        $landingPageViews = (int) $this->landingPageViews($project, $byType, $totals);
        $formViews = (int) $this->formViews($byType);

        $adSignups = (int) ($totals['ad_leads'] + $totals['ad_follows']);
        $subscribers = $this->audience->total($project);
        $followers = $this->audience->followers($project);
        $vips = $this->audience->vips($project);

        $reached = $landingPageViews + $formViews;
        $forecast = $this->forecasts->forProject($project, 0);

        $steps = [
            [
                'key' => 'impressions',
                'label' => 'Impressions',
                'value' => $impressions,
                'note' => null,
                // Minor units, so the client formats it in the project's currency.
                'spend' => (int) round($spend * 100),
                'basis' => self::WINDOW,
                'conversion' => null,
            ],
            [
                'key' => 'ad_clicks',
                'label' => 'Ad clicks',
                'value' => $clicks,
                'note' => null,
                'basis' => self::WINDOW,
                'conversion' => $this->rate($clicks, $impressions),
            ],
            [
                'key' => 'reached',
                'label' => 'Arrived',
                'value' => $landingPageViews + $formViews,
                'note' => null,
                'basis' => self::WINDOW,
                'conversion' => $this->rate($landingPageViews + $formViews, $clicks),
                'branches' => [
                    [
                        'key' => 'landing_page_views',
                        'label' => 'Landing page views',
                        'value' => $landingPageViews,
                        'conversion' => $this->rate($landingPageViews, $clicks),
                    ],
                    [
                        'key' => 'form_views',
                        'label' => 'Form views',
                        'value' => $formViews,
                        'conversion' => $this->rate($formViews, $clicks),
                    ],
                ],
            ],
            [
                'key' => 'signups',
                'label' => 'Signups & followers',
                'value' => $subscribers + $followers,
                'note' => $adSignups > 0
                    ? sprintf('%s from ads in the last %d days', number_format($adSignups), self::WINDOW_DAYS)
                    : null,
                'basis' => self::TOTAL,
                // Measured against the window, not the standing list.
                'conversion' => $this->rate($adSignups, $reached),
            ],
            [
                'key' => 'vip_upgrade',
                'label' => 'VIP upgrades',
                'value' => $vips,
                'note' => null,
                'basis' => self::TOTAL,
                'conversion' => $this->rate($vips, $subscribers + $followers),
            ],
            [
                'key' => 'backers',
                'label' => 'Projected backers',
                'value' => $forecast->expectedBackers,
                // Each group backs at its own rate, so say which produced what.
                'note' => $this->backerNote($forecast->backersBySegment),
                'basis' => self::TOTAL,
                'conversion' => null,
            ],
        ];

        return [
            'window_days' => self::WINDOW_DAYS,
            'steps' => $steps,
        ];
    }

    /**
     * Landing page views come from ads that open a browser — the creator's
     * own page and the Kickstarter page both count. Where Meta reports no
     * landing page views, GA4 sessions stand in.
     *
     * @param  array<string, array<string, float>>  $byType
     * @param  array<string, float>  $totals
     */
    private function landingPageViews(Project $project, array $byType, array $totals): float
    {
        $web = 0.0;

        foreach ([AdType::LandingPage, AdType::Kickstarter, AdType::Unknown] as $type) {
            $web += $byType[$type->value]['ad_landing_page_views'] ?? 0.0;
        }

        if ($web > 0) {
            return $web;
        }

        return max(
            $totals['ad_landing_page_views'],
            $this->series->sum($project, 'sessions', self::WINDOW_DAYS),
        );
    }

    /**
     * Form opens, falling back to clicks on form ads: a click on an instant
     * form ad is the open, so the fallback is exact rather than a guess.
     *
     * @param  array<string, array<string, float>>  $byType
     */
    private function formViews(array $byType): float
    {
        $form = $byType[AdType::InstantForm->value] ?? null;

        if ($form === null) {
            return 0.0;
        }

        return $form['ad_form_views'] > 0 ? $form['ad_form_views'] : $form['ad_clicks'];
    }

    /**
     * Account-level totals exist alongside the per-ad series and should
     * agree; taking the larger survives a partial sync of either.
     */
    private function preferAccount(Project $project, float $fromAds, string $accountMetric): float
    {
        return max($fromAds, $this->series->sum($project, $accountMetric, self::WINDOW_DAYS, 'meta'));
    }

    /** @param  array<string, array<string, float>>  $byType */
    private function sumTypes(array $byType): array
    {
        $totals = array_fill_keys(self::AD_METRICS, 0.0);

        foreach ($byType as $ofType) {
            foreach ($ofType as $metric => $value) {
                $totals[$metric] += $value;
            }
        }

        return $totals;
    }

    private function rate(float $value, float $of): ?float
    {
        return $of > 0 ? round($value / $of * 100, 1) : null;
    }

    /**
     * Where the projected backers come from. A follower is worth ten email
     * addresses, so the split matters more than the total.
     *
     * @param  array<string, int>  $bySegment
     */
    private function backerNote(array $bySegment): ?string
    {
        $parts = [];

        foreach ($bySegment as $segment => $backers) {
            if ($backers > 0) {
                $parts[] = sprintf('%d from %s', $backers, strtolower(BackerRates::segmentLabel($segment)));
            }
        }

        return $parts === [] ? null : implode(', ', $parts);
    }
}
