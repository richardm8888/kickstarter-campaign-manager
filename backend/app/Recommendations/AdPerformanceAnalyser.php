<?php

namespace App\Recommendations;

use App\Forecasting\BackerRates;
use App\Forecasting\ForecastEngine;
use App\Models\Project;
use App\Services\Analytics\AdTotals;

// AdObjective and AdVerdict live in this namespace.

/**
 * Ranks each Meta ad and says what to do with it.
 *
 * Ads are judged on cost per lead, because a lead (an email signup) is the
 * thing that actually predicts funding — clicks and CTR only matter insofar
 * as they produce leads cheaply. Two yardsticks are used:
 *
 *   affordable CPL — what a subscriber is worth to this project, derived
 *                    from average pledge and the subscriber→backer rate.
 *                    An ad above this loses money however good it looks.
 *   account CPL    — what the rest of this account achieves, so budget can
 *                    be moved from the weaker ads to the stronger ones.
 *
 * Without conversion events the engine falls back to CPC and says so,
 * rather than pretending it can judge return.
 */
class AdPerformanceAnalyser
{
    /** Minimum spend before a verdict is more than noise. */
    private const MIN_SPEND = 20.0;

    /** Leads that make a cost-per-lead figure trustworthy. */
    private const MIN_LEADS = 3;

    private const AD_METRICS = [
        'ad_spend',
        'ad_impressions',
        'ad_clicks',
        'ad_leads',
        'ad_view_content',
        'ad_landing_page_views',
        'ad_form_views',
        'ad_follows',
    ];

    public function __construct(
        private readonly ForecastEngine $forecasts,
        private readonly AdTotals $totals,
    ) {}

    public function analyse(Project $project, int $days = 14): array
    {
        $ads = $this->aggregate($project, $days);

        $totalSpend = array_sum(array_column($ads, 'spend'));
        $totalLeads = array_sum(array_column($ads, 'leads'));

        $accountCpl = $totalLeads > 0 ? $totalSpend / $totalLeads : null;
        $affordableCpl = $this->affordableCostPerLead($project);
        $affordableCpf = $this->worthOf($project, BackerRates::FOLLOWERS);
        $hasLeadData = $totalLeads > 0;

        $ads = array_map(
            fn (array $ad) => $ad + $this->judge($ad, $accountCpl, $affordableCpl, $affordableCpf, $hasLeadData),
            $ads,
        );

        usort($ads, function (array $a, array $b) {
            $order = $a['verdict']->priority() <=> $b['verdict']->priority();

            return $order !== 0 ? $order : $b['spend'] <=> $a['spend'];
        });

        $trafficAds = array_filter($ads, fn (array $a) => $a['objective'] === AdObjective::Traffic->value);

        $byType = [];

        foreach (AdType::cases() as $type) {
            $ofType = array_filter($ads, fn (array $a) => $a['ad_type'] === $type->value);

            if ($ofType === []) {
                continue;
            }

            $spend = array_sum(array_column($ofType, 'spend'));
            $conversions = $type === AdType::Kickstarter
                ? array_sum(array_column($ofType, 'follows'))
                : array_sum(array_column($ofType, 'leads'));

            $byType[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'conversion_label' => $type->conversionLabel(),
                'ads' => count($ofType),
                'spend' => round($spend, 2),
                'conversions' => (int) $conversions,
                'cost_per_conversion' => $conversions > 0 ? round($spend / $conversions, 2) : null,
            ];
        }

        return [
            'days' => $days,
            'has_lead_data' => $hasLeadData,
            'by_type' => $byType,
            'traffic_objective_count' => count($trafficAds),
            'traffic_objective_spend' => round(array_sum(array_column($trafficAds, 'spend')), 2),
            'benchmark' => [
                'total_spend' => round($totalSpend, 2),
                'total_leads' => (int) $totalLeads,
                'account_cpl' => $accountCpl !== null ? round($accountCpl, 2) : null,
                'affordable_cpl' => round($affordableCpl, 2),
                'affordable_cost_per_follow' => round($affordableCpf, 2),
            ],
            'ads' => array_map(fn (array $ad) => [
                ...$ad,
                'verdict' => $ad['verdict']->value,
                'verdict_label' => $ad['verdict']->label(),
            ], $ads),
        ];
    }

    /**
     * One judged row per ad, built on the shared per-ad collapse in
     * AdTotals so every reader agrees on what an ad spent and produced.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregate(Project $project, int $days): array
    {
        return array_map(function (array $ad) {
            $dimensions = $ad['dimensions'];
            $totals = $ad['totals'];

            $adType = AdType::tryFrom($dimensions['ad_type'] ?? '') ?? AdType::Unknown;
            $objective = AdObjective::classify(
                $dimensions['optimization_goal'] ?? null,
                $dimensions['objective'] ?? null,
            );

            return [
                'ad_id' => $dimensions['ad_id'],
                'ad_name' => $dimensions['ad_name'] ?? 'Unnamed ad',
                'adset_name' => $dimensions['adset_name'] ?? null,
                'campaign_name' => $dimensions['campaign_name'] ?? null,
                'objective' => $objective->value,
                'objective_label' => $objective->label(),
                'ad_type' => $adType->value,
                'ad_type_label' => $adType->label(),
                'spend' => round($totals['ad_spend'], 2),
                'impressions' => (int) $totals['ad_impressions'],
                'clicks' => (int) $totals['ad_clicks'],
                'leads' => (int) $totals['ad_leads'],
                'view_content' => (int) $totals['ad_view_content'],
                'landing_page_views' => (int) $totals['ad_landing_page_views'],
                'form_views' => (int) $totals['ad_form_views'],
                'follows' => (int) $totals['ad_follows'],
                'cost_per_follow' => $this->ratio($totals['ad_spend'], $totals['ad_follows']),
                'ctr' => $this->ratio($totals['ad_clicks'] * 100, $totals['ad_impressions']),
                'cpc' => $this->ratio($totals['ad_spend'], $totals['ad_clicks']),
                'cpl' => $this->ratio($totals['ad_spend'], $totals['ad_leads']),
                'cost_per_page_view' => $this->ratio($totals['ad_spend'], $totals['ad_landing_page_views']),
                'lead_rate' => $this->ratio($totals['ad_leads'] * 100, $totals['ad_clicks'], 1),
            ];
        }, $this->totals->perAd($project, self::AD_METRICS, $days));
    }

    private function ratio(float $numerator, float $denominator, int $precision = 2): ?float
    {
        return $denominator > 0 ? round($numerator / $denominator, $precision) : null;
    }

    /**
     * What one email subscriber is worth: the share who become backers,
     * times the average pledge. Spending more than this per lead means
     * the campaign cannot pay for its own traffic.
     */
    private function affordableCostPerLead(Project $project): float
    {
        return $this->worthOf($project, BackerRates::STANDARD);
    }

    /** What one member of a segment is worth, in currency units. */
    private function worthOf(Project $project, string $segment): float
    {
        $input = $this->forecasts->inputFor($project, 0);

        return ($input->averagePledge / 100) * ($input->backerRates[$segment] ?? 0.0);
    }

    /** @return array{verdict: AdVerdict, reason: string, action: string} */
    private function judge(
        array $ad,
        ?float $accountCpl,
        float $affordableCpl,
        float $affordableCpf,
        bool $hasLeadData,
    ): array
    {
        // Not enough money spent to tell signal from noise.
        if ($ad['spend'] < self::MIN_SPEND && ($ad['leads'] ?? 0) < self::MIN_LEADS) {
            return [
                'verdict' => AdVerdict::Learning,
                'reason' => sprintf(
                    'Only %s spent so far — too early to judge.',
                    $this->money($ad['spend']),
                ),
                'action' => 'Leave it running until it has spent about '.$this->money(self::MIN_SPEND).'.',
            ];
        }

        // Ads driving Kickstarter follows produce no email signups by
        // design; a follower is notified at launch, so judge them on that.
        if (($ad['follows'] ?? 0) > 0 && $ad['leads'] === 0) {
            return $this->judgeFollowAd($ad, $affordableCpf);
        }

        // An ad optimised for traffic was never asked to produce signups,
        // so judging it on cost per signup would blame the wrong thing.
        if (($ad['objective'] ?? null) === AdObjective::Traffic->value && $ad['leads'] === 0) {
            return $this->judgeTrafficAd($ad);
        }

        // Spending with nothing to show for it.
        if ($hasLeadData && $ad['leads'] === 0) {
            return [
                'verdict' => AdVerdict::Drop,
                'reason' => sprintf(
                    '%s spent and no signups, while other ads are converting.',
                    $this->money($ad['spend']),
                ),
                'action' => 'Turn this ad off and move its budget to your best performer.',
            ];
        }

        // No conversion tracking anywhere: fall back to traffic cost.
        if (! $hasLeadData) {
            return $this->judgeOnClicks($ad);
        }

        $cpl = $ad['cpl'];

        if ($cpl === null) {
            return $this->judgeOnClicks($ad);
        }

        if ($cpl > $affordableCpl) {
            return [
                'verdict' => AdVerdict::Drop,
                'reason' => sprintf(
                    'Each signup costs %s but a subscriber is only worth about %s to this campaign.',
                    $this->money($cpl),
                    $this->money($affordableCpl),
                ),
                'action' => 'Pause it — at this price the campaign loses money on every signup.',
            ];
        }

        $benchmark = $accountCpl ?? $affordableCpl;

        // Cheaper than the rest of the account, or comfortably below what a
        // subscriber is worth — the second matters when it is the only ad,
        // where being "average" says nothing.
        $beatsAccount = $cpl <= $benchmark * 0.75;
        $beatsWorth = $cpl <= $affordableCpl * 0.5;

        if ($beatsAccount || $beatsWorth) {
            return [
                'verdict' => AdVerdict::Scale,
                'reason' => $beatsAccount
                    ? sprintf(
                        'Signups cost %s against an account average of %s — your cheapest source right now.',
                        $this->money($cpl),
                        $this->money($benchmark),
                    )
                    : sprintf(
                        'Signups cost %s, well under the %s a subscriber is worth to you.',
                        $this->money($cpl),
                        $this->money($affordableCpl),
                    ),
                'action' => 'Raise its budget by 20-30% and check again in a few days.',
            ];
        }

        if ($cpl >= $benchmark * 1.5) {
            return [
                'verdict' => AdVerdict::Fix,
                'reason' => sprintf(
                    'Signups cost %s, well above the %s average, but still under what you can afford.',
                    $this->money($cpl),
                    $this->money($benchmark),
                ),
                'action' => 'Refresh the creative or tighten the audience before spending more.',
            ];
        }

        return [
            'verdict' => AdVerdict::Keep,
            'reason' => sprintf('Signups cost %s, in line with the rest of the account.', $this->money($cpl)),
            'action' => 'Leave it as it is and keep an eye on the trend.',
        ];
    }

    /**
     * A Kickstarter follow is worth more than an email address, because
     * Kickstarter notifies followers the instant the campaign opens.
     */
    private function judgeFollowAd(array $ad, float $affordableCostPerFollow): array
    {
        $costPerFollow = $ad['cost_per_follow'];

        if ($costPerFollow !== null && $affordableCostPerFollow > 0 && $costPerFollow > $affordableCostPerFollow) {
            return [
                'verdict' => AdVerdict::Drop,
                'reason' => sprintf(
                    'Each Kickstarter follow costs %s, above the %s one is worth to this campaign.',
                    $this->money($costPerFollow),
                    $this->money($affordableCostPerFollow),
                ),
                'action' => 'Pause it, or move the budget to your lead form ads.',
            ];
        }

        return [
            'verdict' => AdVerdict::Scale,
            'reason' => sprintf(
                '%d Kickstarter follows at %s each — followers are notified the moment you launch.',
                $ad['follows'],
                $costPerFollow !== null ? $this->money($costPerFollow) : 'an unknown cost',
            ),
            'action' => 'Keep funding this; follows are the closest thing to a booked backer.',
        ];
    }

    /**
     * Traffic-objective ads are buying visits, which is what they were
     * asked to do. The problem is the instruction, not the creative:
     * Meta optimises for whoever clicks, not whoever signs up.
     */
    private function judgeTrafficAd(array $ad): array
    {
        $costPerView = $ad['cost_per_page_view'];

        return [
            'verdict' => AdVerdict::Fix,
            'reason' => sprintf(
                'Optimised for landing page views, not signups%s. Meta is buying the cheapest visits it can find, whether or not they convert.',
                $costPerView !== null ? sprintf(' — %s per visit', $this->money($costPerView)) : '',
            ),
            'action' => 'Duplicate it into a Leads or Sales campaign so Meta optimises for signups instead of clicks.',
        ];
    }

    /** Fallback judgement when there are no conversion events to work with. */
    private function judgeOnClicks(array $ad): array
    {
        $cpc = $ad['cpc'];

        if ($cpc === null) {
            return [
                'verdict' => AdVerdict::Fix,
                'reason' => sprintf('%s spent with no clicks at all.', $this->money($ad['spend'])),
                'action' => 'Replace the creative — nobody is engaging with it.',
            ];
        }

        return [
            'verdict' => $cpc > 2.0 ? AdVerdict::Fix : AdVerdict::Keep,
            'reason' => sprintf(
                'Clicks cost %s. Without Lead events we cannot tell whether they convert.',
                $this->money($cpc),
            ),
            'action' => 'Install the Lead event so ads can be judged on signups, not clicks.',
        ];
    }

    private function money(float $amount): string
    {
        return '£'.number_format($amount, 2);
    }
}
