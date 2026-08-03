<?php

namespace App\Recommendations;

use App\Forecasting\ForecastEngine;
use App\Models\Project;
use Illuminate\Support\Collection;

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
        'ad_spend' => 'spend',
        'ad_impressions' => 'impressions',
        'ad_clicks' => 'clicks',
        'ad_leads' => 'leads',
        'ad_view_content' => 'view_content',
    ];

    public function __construct(private readonly ForecastEngine $forecasts) {}

    public function analyse(Project $project, int $days = 14): array
    {
        $ads = $this->aggregate($project, $days);

        $totalSpend = array_sum(array_column($ads, 'spend'));
        $totalLeads = array_sum(array_column($ads, 'leads'));

        $accountCpl = $totalLeads > 0 ? $totalSpend / $totalLeads : null;
        $affordableCpl = $this->affordableCostPerLead($project);
        $hasLeadData = $totalLeads > 0;

        $ads = array_map(
            fn (array $ad) => $ad + $this->judge($ad, $accountCpl, $affordableCpl, $hasLeadData),
            $ads,
        );

        usort($ads, function (array $a, array $b) {
            $order = $a['verdict']->priority() <=> $b['verdict']->priority();

            return $order !== 0 ? $order : $b['spend'] <=> $a['spend'];
        });

        return [
            'days' => $days,
            'has_lead_data' => $hasLeadData,
            'benchmark' => [
                'total_spend' => round($totalSpend, 2),
                'total_leads' => (int) $totalLeads,
                'account_cpl' => $accountCpl !== null ? round($accountCpl, 2) : null,
                'affordable_cpl' => round($affordableCpl, 2),
            ],
            'ads' => array_map(fn (array $ad) => [
                ...$ad,
                'verdict' => $ad['verdict']->value,
                'verdict_label' => $ad['verdict']->label(),
            ], $ads),
        ];
    }

    /**
     * Collapse the append-only snapshots into one row per ad: repeated
     * reports of a day are restatements, so the latest wins per day.
     *
     * @return list<array<string, mixed>>
     */
    private function aggregate(Project $project, int $days): array
    {
        $snapshots = $project->metricSnapshots()
            ->where('source', 'meta')
            ->whereIn('metric', array_keys(self::AD_METRICS))
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        $ads = [];

        foreach ($snapshots as $snapshot) {
            $adId = $snapshot->dimensions['ad_id'] ?? null;

            if ($adId === null) {
                continue;
            }

            $field = self::AD_METRICS[$snapshot->metric];
            $date = $snapshot->recorded_at->toDateString();

            $ads[$adId]['meta'] = [
                'ad_id' => $adId,
                'ad_name' => $snapshot->dimensions['ad_name'] ?? 'Unnamed ad',
                'adset_name' => $snapshot->dimensions['adset_name'] ?? null,
                'campaign_name' => $snapshot->dimensions['campaign_name'] ?? null,
            ];

            // Last write per (ad, metric, day) wins.
            $ads[$adId]['days'][$date][$field] = (float) $snapshot->value;
        }

        return array_values(array_map(function (array $ad) {
            $days = new Collection($ad['days']);

            $totals = [];
            foreach (self::AD_METRICS as $field) {
                $totals[$field] = (float) $days->sum(fn (array $day) => $day[$field] ?? 0);
            }

            return $ad['meta'] + [
                'spend' => round($totals['spend'], 2),
                'impressions' => (int) $totals['impressions'],
                'clicks' => (int) $totals['clicks'],
                'leads' => (int) $totals['leads'],
                'view_content' => (int) $totals['view_content'],
                'ctr' => $totals['impressions'] > 0
                    ? round($totals['clicks'] / $totals['impressions'] * 100, 2)
                    : null,
                'cpc' => $totals['clicks'] > 0
                    ? round($totals['spend'] / $totals['clicks'], 2)
                    : null,
                'cpl' => $totals['leads'] > 0
                    ? round($totals['spend'] / $totals['leads'], 2)
                    : null,
                'lead_rate' => $totals['clicks'] > 0
                    ? round($totals['leads'] / $totals['clicks'] * 100, 1)
                    : null,
            ];
        }, $ads));
    }

    /**
     * What one email subscriber is worth: the share who become backers,
     * times the average pledge. Spending more than this per lead means
     * the campaign cannot pay for its own traffic.
     */
    private function affordableCostPerLead(Project $project): float
    {
        $input = $this->forecasts->inputFor($project, 0);

        return ($input->averagePledge / 100) * $input->subscriberToBackerRate;
    }

    /** @return array{verdict: AdVerdict, reason: string, action: string} */
    private function judge(array $ad, ?float $accountCpl, float $affordableCpl, bool $hasLeadData): array
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

        if ($cpl <= $benchmark * 0.75) {
            return [
                'verdict' => AdVerdict::Scale,
                'reason' => sprintf(
                    'Signups cost %s against an account average of %s — your cheapest source right now.',
                    $this->money($cpl),
                    $this->money($benchmark),
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
