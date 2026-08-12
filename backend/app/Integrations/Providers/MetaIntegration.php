<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use App\Recommendations\AdType;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaIntegration extends BaseIntegration
{
    /**
     * Email signups: an Instant Form submission, which is an address the
     * creator owns and can mail at launch.
     *
     * The generic "lead" action type is deliberately absent — Meta uses it
     * as an umbrella over both form submissions and pixel leads, so counting
     * it would conflate an owned contact with a Kickstarter follow.
     */
    public const LEAD_ACTIONS = [
        'leadgen_grouped',
        'onsite_conversion.lead_grouped',
        'onsite_web_lead',
    ];

    /**
     * A pixel Lead. What it means depends on where the ad sent people: a
     * follow on a Kickstarter page, an owned email address on the
     * creator's own page. See AdType.
     */
    public const PIXEL_LEAD_ACTIONS = [
        'offsite_conversion.fb_pixel_lead',
        'offsite_conversion.fb_pixel_complete_registration',
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
     * Instant form opened. Not every account reports it, so the funnel
     * falls back to clicks on form ads — a click on one is an open.
     */
    public const FORM_VIEW_ACTIONS = ['onsite_conversion.lead_form_open', 'lead_form_open'];

    /**
     * Every effective_status Meta defines, so the ads edge returns the
     * archived and deleted ones too rather than quietly omitting them.
     */
    private const ALL_STATUSES = [
        'ACTIVE', 'PAUSED', 'DELETED', 'ARCHIVED',
        'ADSET_PAUSED', 'CAMPAIGN_PAUSED',
        'PENDING_REVIEW', 'DISAPPROVED', 'PREAPPROVED',
        'PENDING_BILLING_INFO', 'IN_PROCESS', 'WITH_ISSUES',
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
                'help' => 'Business Settings → Users → System users → Generate token. Tick ads_read, plus leads_retrieval if you run Instant Form ads, and set it never to expire (a Graph API Explorer token lasts about an hour). For form leads the same system user also needs Leads Access on the Page under Accounts → Pages.',
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

        // An explicit range rather than a preset, so the lookback can match
        // the longest window the app offers (and be widened per project).
        $lookback = $this->lookbackDays();

        $query = [
            'access_token' => $credentials['access_token'],
            'level' => 'ad',
            'time_range' => json_encode([
                'since' => now()->subDays($lookback)->toDateString(),
                'until' => now()->toDateString(),
            ]),
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


        $catalogue = $this->adTypes($credentials);

        $rows = [];
        $accountTotals = [];

        foreach ($days as $row) {
            $date = $row['date_stop'] ?? now()->toDateString();
            $adId = (string) ($row['ad_id'] ?? 'unknown');
            $adMeta = $catalogue['ads'][$adId] ?? null;
            $adType = $adMeta['type'] ?? AdType::Unknown;

            // Two very different kinds of missing. An ad absent from a
            // catalogue we read is one Meta will not list even when asked
            // for every status — deleted, and certainly not running. An ad
            // absent because the call failed tells us nothing, and unknown
            // has to keep meaning running or a broken request would empty
            // the page.
            $status = $adMeta['status'] ?? ($catalogue['readable'] ? 'DELETED' : 'ACTIVE');

            $spend = (float) ($row['spend'] ?? 0);
            $impressions = (float) ($row['impressions'] ?? 0);
            $clicks = (float) ($row['clicks'] ?? 0);
            $formLeads = $this->countActions($row['actions'] ?? [], self::LEAD_ACTIONS);
            $pixelLeads = $this->countActions($row['actions'] ?? [], self::PIXEL_LEAD_ACTIONS);
            $viewContent = $this->countActions($row['actions'] ?? [], self::VIEW_CONTENT_ACTIONS);
            $landingPageViews = $this->countActions($row['actions'] ?? [], self::LANDING_PAGE_VIEW_ACTIONS);
            $formViews = $this->countActions($row['actions'] ?? [], self::FORM_VIEW_ACTIONS);

            // The same pixel event means a follow on a Kickstarter page and
            // an owned contact on the creator's own page.
            $follows = $adType->pixelLeadIsFollow() ? $pixelLeads : 0.0;
            $leads = $adType->pixelLeadIsFollow() ? $formLeads : max($formLeads, $pixelLeads);

            $dimensions = [
                'ad_id' => $adId,
                'ad_name' => $row['ad_name'] ?? 'Unnamed ad',
                'adset_name' => $row['adset_name'] ?? null,
                'campaign_name' => $row['campaign_name'] ?? null,
                'objective' => $row['objective'] ?? null,
                'optimization_goal' => $row['optimization_goal'] ?? null,
                'ad_type' => $adType->value,
                'ad_status' => $status,
            ];

            foreach ([
                'ad_spend' => $spend,
                'ad_impressions' => $impressions,
                'ad_clicks' => $clicks,
                'ad_leads' => $leads,
                'ad_view_content' => $viewContent,
                'ad_landing_page_views' => $landingPageViews,
                'ad_form_views' => $formViews,
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

    /**
     * Classifies every ad by where it sends people, which decides how its
     * conversions are read.
     *
     * @return array{ads: array<string, array{type: AdType, status: string}>, readable: bool}
     */
    private function adTypes(array $credentials): array
    {
        $accountId = ltrim($credentials['ad_account_id'], 'act_');
        $version = config('services.meta.api_version');

        try {
            $ads = $this->paginate("https://graph.facebook.com/{$version}/act_{$accountId}/ads", [
                'access_token' => $credentials['access_token'],
                // effective_status rather than status: an ad set to ACTIVE
                // inside a paused campaign is not running, and telling a
                // creator to fix an ad nobody can see wastes their morning.
                'fields' => 'id,effective_status,creative{object_story_spec,asset_feed_spec,object_type}',
                // This edge hides archived and deleted ads unless asked,
                // while insights keeps reporting their spend. Left alone,
                // the ads a creator most wants gone are exactly the ones
                // missing from the catalogue — and so the ones that keep
                // being treated as live.
                'filtering' => json_encode([[
                    'field' => 'effective_status',
                    'operator' => 'IN',
                    'value' => self::ALL_STATUSES,
                ]]),
                'limit' => 200,
            ]);
        } catch (RequestException $e) {
            // Without creatives every ad is Unknown, which still reports —
            // it just cannot tell a follow from a signup.
            Log::warning('Could not read Meta creatives; ads will be unclassified.', [
                'error' => $e->getMessage(),
            ]);

            return ['ads' => [], 'readable' => false];
        }

        $types = [];

        foreach ($ads as $ad) {
            if (! isset($ad['id'])) {
                continue;
            }

            $types[(string) $ad['id']] = [
                'type' => AdType::fromCreative($ad['creative'] ?? []),
                // A listed ad with no status is treated as running, so a
                // field we failed to fetch hides nothing.
                'status' => strtoupper((string) ($ad['effective_status'] ?? 'ACTIVE')),
            ];
        }

        return ['ads' => $types, 'readable' => true];
    }

    /**
     * How far back each sync reads. Defaults to 90 days so ads that stopped
     * running still appear, and so every range the Ads screen offers is
     * backed by real data rather than silently truncated.
     */
    private function lookbackDays(): int
    {
        $configured = (int) ($this->record()->settings['insights_days'] ?? 0);

        return $configured > 0 ? min($configured, 365) : 90;
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
                'time_range' => json_encode([
                    'since' => now()->subDays($days)->toDateString(),
                    'until' => now()->toDateString(),
                ]),
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

        // Forms belong to ads, so walk the ads that ran in the window. The
        // ad and campaign names travel with each lead: they are what makes
        // a "lead source" meaningful once the contact reaches the mailing list.
        $ads = $this->paginate("https://graph.facebook.com/{$version}/act_{$accountId}/ads", [
            'access_token' => $credentials['access_token'],
            'fields' => 'id,name,campaign{name}',
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
                    'ad_name' => $ad['name'] ?? null,
                    'campaign_name' => $ad['campaign']['name'] ?? null,
                    // Every answer the form collected, including custom questions.
                    'fields' => $fields,
                ];
            }
        }

        return $leads;
    }

    /**
     * Meta returns lead answers as [{name, values: []}]. Question names are
     * normalised to snake_case keys ("Lead Source" → lead_source) so they
     * line up with custom field keys in an email provider.
     */
    private function flattenFieldData(array $fieldData): array
    {
        $fields = [];

        foreach ($fieldData as $field) {
            $key = self::fieldKey((string) ($field['name'] ?? ''));
            $value = $field['values'][0] ?? null;

            if ($key !== '' && $value !== null && $value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    public static function fieldKey(string $name): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)) ?? '', '_');
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
