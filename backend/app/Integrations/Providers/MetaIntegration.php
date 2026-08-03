<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaIntegration extends BaseIntegration
{
    /**
     * Meta reports conversions under several action types depending on
     * whether they were attributed to the pixel, the site or an on-site
     * event, so each of ours accepts a family of names.
     */
    public const LEAD_ACTIONS = [
        'lead',
        'offsite_conversion.fb_pixel_lead',
        'onsite_conversion.lead_grouped',
        'onsite_web_lead',
        'leadgen_grouped',
        'offsite_conversion.fb_pixel_complete_registration',
        'complete_registration',
    ];

    public const VIEW_CONTENT_ACTIONS = [
        'view_content',
        'offsite_conversion.fb_pixel_view_content',
        'onsite_web_view_content',
    ];

    /**
     * Meta's own count of clicks that actually loaded the page. Kept apart
     * from ViewContent: this one needs no pixel event, so comparing the two
     * reveals whether the creator's ViewContent tag is firing everywhere.
     */
    public const LANDING_PAGE_VIEW_ACTIONS = ['landing_page_view'];

    /**
     * Instant Form submissions — an email address the creator owns and can
     * mail directly. Distinct from a pixel "Lead" fired on someone else's
     * page (see follow_actions), which is a following, not a contact.
     */
    public const FORM_LEAD_ACTIONS = [
        'leadgen_grouped',
        'onsite_conversion.lead_grouped',
        'onsite_web_lead',
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

        $url = "https://graph.facebook.com/{$version}/act_{$accountId}/insights";

        $query = [
            'access_token' => $credentials['access_token'],
            'level' => 'ad',
            'date_preset' => 'last_14d',
            'time_increment' => 1,
            'limit' => 500,
        ];

        $baseFields = 'ad_id,ad_name,campaign_name,adset_name,spend,impressions,clicks,actions';

        try {
            // The objective fields decide how an ad is judged, but field
            // availability varies by API version, so fall back rather than
            // lose the whole sync over them.
            $days = $this->paginate($url, $query + [
                'fields' => $baseFields.',objective,optimization_goal',
            ]);
        } catch (RequestException $e) {
            Log::info('Meta rejected the objective fields; syncing without them.', [
                'error' => $e->getMessage(),
            ]);

            $days = $this->paginate($url, $query + ['fields' => $baseFields]);
        }

        $followActions = $this->actionTypesFor('follow');
        $viewContentActions = $this->actionTypesFor('view_content');

        // Anything claimed as a follow is not also counted as a signup.
        $leadActions = array_values(array_diff($this->actionTypesFor('lead'), $followActions));

        $rows = [];
        $accountTotals = [];

        foreach ($days as $row) {
            $date = $row['date_stop'] ?? now()->toDateString();

            $spend = (float) ($row['spend'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $clicks = (float) ($row['clicks'] ?? 0);
            $leads = $this->countActions($row['actions'] ?? [], $leadActions);
            $viewContent = $this->countActions($row['actions'] ?? [], $viewContentActions);
            $landingPageViews = $this->countActions($row['actions'] ?? [], self::LANDING_PAGE_VIEW_ACTIONS);
            $follows = $this->countActions($row['actions'] ?? [], $followActions);

            $dimensions = [
                'ad_id' => (string) ($row['ad_id'] ?? 'unknown'),
                'ad_name' => $row['ad_name'] ?? 'Unnamed ad',
                'adset_name' => $row['adset_name'] ?? null,
                'campaign_name' => $row['campaign_name'] ?? null,
                'objective' => $row['objective'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
            ];

            foreach ([
                'ad_spend' => $spend,
                'ad_impressions' => $impressions,
                'ad_clicks' => $clicks,
                'ad_leads' => $leads,
                'ad_view_content' => $viewContent,
                'ad_landing_page_views' => $landingPageViews,
                'ad_follows' => $follows,
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

    /**
     * Meta reports the same conversion under several action types — an
     * aggregate ("lead") alongside the specific source
     * ("offsite_conversion.fb_pixel_lead"). Summing would double count and
     * taking the first match lets a leading zero win, so take the largest.
     *
     * @param  list<array{action_type?: string, value?: mixed}>  $actions
     */
    private function countActions(array $actions, array $wanted): float
    {
        $counts = [0.0];

        foreach ($actions as $action) {
            if (in_array($action['action_type'] ?? '', $wanted, true)) {
                $counts[] = (float) ($action['value'] ?? 0);
            }
        }

        return max($counts);
    }

    /** Action types this project treats as each conversion, overridable per project. */
    private function actionTypesFor(string $event): array
    {
        $configured = $this->record()->settings["{$event}_actions"] ?? null;

        if (is_array($configured)) {
            return $configured;
        }

        return match ($event) {
            'lead' => self::LEAD_ACTIONS,
            'view_content' => self::VIEW_CONTENT_ACTIONS,
            // Opt-in: only set when a creator sends traffic to a Kickstarter
            // page, where a pixel "Lead" means a follow rather than a contact.
            'follow' => [],
            default => [],
        };
    }

    /**
     * Every distinct action type the account reported, with totals — used
     * by the setup guide to let a creator map custom conversions.
     */
    public function discoverActionTypes(int $days = 14): array
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return [];
        }

        $credentials = $record->credentials;
        $accountId = ltrim($credentials['ad_account_id'], 'act_');
        $version = config('services.meta.api_version');

        $rows = $this->paginate(
            "https://graph.facebook.com/{$version}/act_{$accountId}/insights",
            [
                'access_token' => $credentials['access_token'],
                'level' => 'account',
                'date_preset' => $days <= 7 ? 'last_7d' : 'last_14d',
                'fields' => 'actions',
                'limit' => 100,
            ],
        );

        $totals = [];

        foreach ($rows as $row) {
            foreach ($row['actions'] ?? [] as $action) {
                $type = $action['action_type'] ?? null;

                if ($type !== null) {
                    $totals[$type] = ($totals[$type] ?? 0) + (float) ($action['value'] ?? 0);
                }
            }
        }

        arsort($totals);

        return array_map(
            fn (string $type, float $total) => ['action_type' => $type, 'total' => $total],
            array_keys($totals),
            array_values($totals),
        );
    }

    /**
     * Instant Form submissions, newest first. These live inside Facebook —
     * unless they are pulled out, the creator cannot email the people who
     * signed up.
     *
     * Requires the leads_retrieval permission on the token.
     *
     * @return list<array{id: string, email: ?string, name: ?string, created_time: ?string}>
     */
    public function fetchFormLeads(int $days = 30): array
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return [];
        }

        $credentials = $record->credentials;
        $accountId = ltrim($credentials['ad_account_id'], 'act_');
        $version = config('services.meta.api_version');
        $since = now()->subDays($days)->timestamp;

        // Forms belong to ads, so walk the ads that ran in the window.
        $ads = $this->paginate("https://graph.facebook.com/{$version}/act_{$accountId}/ads", [
            'access_token' => $credentials['access_token'],
            'fields' => 'id',
            'limit' => 200,
        ]);

        $leads = [];

        foreach ($ads as $ad) {
            $rows = $this->paginate("https://graph.facebook.com/{$version}/{$ad['id']}/leads", [
                'access_token' => $credentials['access_token'],
                'fields' => 'id,created_time,field_data',
                'filtering' => json_encode([[
                    'field' => 'time_created',
                    'operator' => 'GREATER_THAN',
                    'value' => $since,
                ]]),
                'limit' => 200,
            ], maxPages: 5);

            foreach ($rows as $row) {
                $fields = $this->flattenFieldData($row['field_data'] ?? []);

                $leads[] = [
                    'id' => (string) ($row['id'] ?? ''),
                    'email' => $fields['email'] ?? null,
                    'name' => $fields['full_name'] ?? $fields['first_name'] ?? null,
                    'created_time' => $row['created_time'] ?? null,
                ];
            }
        }

        return $leads;
    }

    /** Meta returns lead answers as [{name, values: []}]. */
    private function flattenFieldData(array $fieldData): array
    {
        $fields = [];

        foreach ($fieldData as $field) {
            $name = strtolower((string) ($field['name'] ?? ''));
            $value = $field['values'][0] ?? null;

            if ($name !== '' && $value !== null) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    /** Follows Meta's cursor pagination so large accounts are not truncated. */
    private function paginate(string $url, array $query, int $maxPages = 10): array
    {
        $rows = [];
        $next = $url;
        $params = $query;

        for ($page = 0; $page < $maxPages && $next !== null; $page++) {
            $response = Http::get($next, $params)->throw()->json();

            $rows = array_merge($rows, $response['data'] ?? []);

            // Subsequent pages carry their parameters in the cursor URL.
            $next = $response['paging']['next'] ?? null;
            $params = [];
        }

        return $rows;
    }
}
