<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;

class MetaIntegration extends BaseIntegration
{
    /**
     * Meta reports conversions under several action types depending on
     * whether they were attributed to the pixel, the site or an on-site
     * event, so each of ours accepts a family of names.
     */
    private const LEAD_ACTIONS = [
        'lead',
        'offsite_conversion.fb_pixel_lead',
        'onsite_conversion.lead_grouped',
        'onsite_web_lead',
    ];

    private const VIEW_CONTENT_ACTIONS = [
        'view_content',
        'offsite_conversion.fb_pixel_view_content',
    ];

    public function provider(): string
    {
        return 'meta';
    }

    public function displayName(): string
    {
        return 'Meta Ads';
    }

    public function credentialFields(): array
    {
        return [
            'access_token' => [
                'label' => 'Access token',
                'help' => 'Business Settings → Users → System users → Generate token, with the ads_read permission. Set it never to expire; a Graph API Explorer token lasts about an hour.',
                'type' => 'password',
            ],
            'ad_account_id' => [
                'label' => 'Ad account ID',
                'help' => 'Digits only, from Ads Manager. Enter 123456789, not act_123456789.',
                'type' => 'password',
                'placeholder' => '123456789',
            ],
        ];
    }

    public function docsUrl(): ?string
    {
        return 'https://developers.facebook.com/docs/marketing-api/get-started';
    }

    /**
     * Pulls insights per ad per day. Account-level totals are derived from
     * the same response so the dashboard and the per-ad view can never
     * disagree, and only one API call is made.
     */
    protected function fetchMetrics(array $credentials): array
    {
        $accountId = ltrim($credentials['ad_account_id'], 'act_');
        $version = config('services.meta.api_version');

        $days = Http::baseUrl("https://graph.facebook.com/{$version}")
            ->get("/act_{$accountId}/insights", [
                'access_token' => $credentials['access_token'],
                'level' => 'ad',
                'date_preset' => 'last_14d',
                'time_increment' => 1,
                'limit' => 500,
                'fields' => 'ad_id,ad_name,campaign_name,adset_name,spend,impressions,clicks,actions',
            ])->throw()->json('data', []);

        $rows = [];
        $accountTotals = [];

        foreach ($days as $row) {
            $date = $row['date_stop'] ?? now()->toDateString();

            $spend = (float) ($row['spend'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $clicks = (float) ($row['clicks'] ?? 0);
            $leads = $this->countActions($row['actions'] ?? [], self::LEAD_ACTIONS);
            $viewContent = $this->countActions($row['actions'] ?? [], self::VIEW_CONTENT_ACTIONS);

            $dimensions = [
                'ad_id' => (string) ($row['ad_id'] ?? 'unknown'),
                'ad_name' => $row['ad_name'] ?? 'Unnamed ad',
                'adset_name' => $row['adset_name'] ?? null,
                'campaign_name' => $row['campaign_name'] ?? null,
            ];

            foreach ([
                'ad_spend' => $spend,
                'ad_impressions' => $impressions,
                'ad_clicks' => $clicks,
                'ad_leads' => $leads,
                'ad_view_content' => $viewContent,
            ] as $metric => $value) {
                $rows[] = [
                    'metric' => $metric,
                    'value' => $value,
                    'recorded_at' => $date,
                    'dimensions' => $dimensions,
                ];
            }

            $accountTotals[$date] ??= ['spend' => 0.0, 'impressions' => 0.0, 'clicks' => 0.0];
            $accountTotals[$date]['spend'] += $spend;
            $accountTotals[$date]['impressions'] += $impressions;
            $accountTotals[$date]['clicks'] += $clicks;
        }

        foreach ($accountTotals as $date => $totals) {
            $rows[] = ['metric' => 'spend', 'value' => $totals['spend'], 'recorded_at' => $date];
            $rows[] = ['metric' => 'impressions', 'value' => $totals['impressions'], 'recorded_at' => $date];
            $rows[] = ['metric' => 'clicks', 'value' => $totals['clicks'], 'recorded_at' => $date];

            // Rates are derived rather than read, so they always agree with the totals.
            if ($totals['clicks'] > 0) {
                $rows[] = ['metric' => 'cpc', 'value' => round($totals['spend'] / $totals['clicks'], 4), 'recorded_at' => $date];
            }

            if ($totals['impressions'] > 0) {
                $rows[] = ['metric' => 'cpm', 'value' => round($totals['spend'] / $totals['impressions'] * 1000, 4), 'recorded_at' => $date];
                $rows[] = ['metric' => 'ctr', 'value' => round($totals['clicks'] / $totals['impressions'] * 100, 4), 'recorded_at' => $date];
            }
        }

        return $rows;
    }

    /** @param  list<array{action_type?: string, value?: mixed}>  $actions */
    private function countActions(array $actions, array $wanted): float
    {
        foreach ($actions as $action) {
            if (in_array($action['action_type'] ?? '', $wanted, true)) {
                return (float) ($action['value'] ?? 0);
            }
        }

        return 0.0;
    }
}
