<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use App\Integrations\Support\GoogleServiceAccount;
use App\Services\Analytics\Region;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Ga4Integration extends BaseIntegration
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    /**
     * Matched to the longest window the analytics screen offers, so a
     * range is backed by real data rather than silently truncated.
     */
    private const LOOKBACK = 90;

    /**
     * What a signup is called in GA4. `generate_lead` is the recommended
     * event, but a form plugin may send `sign_up` or a custom name, and
     * reporting zero because the name differs is worse than counting a
     * near-synonym.
     */
    private const LEAD_EVENTS = ['generate_lead', 'sign_up', 'subscribe', 'newsletter_signup'];

    public function provider(): string
    {
        return 'ga4';
    }

    public function displayName(): string
    {
        return 'Google Analytics 4';
    }

    public function credentialFields(): array
    {
        return [
            'property_id' => [
                'label' => 'Property ID',
                'help' => 'GA4 → Admin → Property settings. A number like 123456789 — not the "G-" measurement ID.',
                'type' => 'password',
                'placeholder' => '123456789',
            ],
            'service_account_json' => [
                'label' => 'Service account key (JSON)',
                'help' => 'Google Cloud → IAM → Service accounts → Keys → Add key → JSON. Paste the whole file, then add the key\'s client_email as a Viewer on your GA4 property.',
                'type' => 'textarea',
                'placeholder' => '{"type": "service_account", ...}',
            ],
        ];
    }

    public function docsUrl(): ?string
    {
        return 'https://developers.google.com/analytics/devguides/reporting/data/v1/quickstart-client-libraries';
    }

    protected function validateCredentials(array $credentials): void
    {
        try {
            GoogleServiceAccount::parseKey($credentials['service_account_json']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages([
                'credentials' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Six reports across two batched calls.
     *
     * Sessions and signups have to be asked for separately. A report
     * filtered to lead events scopes every metric in it, so asking for
     * sessions alongside them counts only the sessions that already
     * converted — which reports a conversion rate of about 100% and looks
     * like wonderful news.
     *
     * The cuts are stored under their own metric names rather than as
     * dimensioned copies of `sessions`, because the read side collapses a
     * metric to one figure per day: three source rows for one Tuesday
     * would silently become whichever landed last.
     */
    protected function fetchMetrics(array $credentials): array
    {
        $key = GoogleServiceAccount::parseKey($credentials['service_account_json']);
        $token = app(GoogleServiceAccount::class)->accessToken($key, self::SCOPE);

        $property = $credentials['property_id'];

        $totals = $this->batch($token, $property, [
            [
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                ],
                'dimensions' => [['name' => 'date']],
            ],
            [
                'metrics' => [['name' => 'eventCount']],
                'dimensions' => [['name' => 'date'], ['name' => 'eventName']],
                'dimensionFilter' => $this->onlyLeadEvents(),
            ],
        ]);

        $breakdowns = $this->batch($token, $property, [
            $this->sessionsBy('sessionSource'),
            $this->leadsBy('sessionSource'),
            $this->sessionsBy('countryId'),
            $this->leadsBy('countryId'),
        ]);

        return [
            ...$this->traffic($totals[0] ?? []),
            ...$this->leads($totals[1] ?? []),
            ...$this->segmented(
                $breakdowns[0] ?? [],
                $breakdowns[1] ?? [],
                'source',
                fn (string $value) => $value === '(direct)' || $value === '' ? 'Direct' : $value,
            ),
            ...$this->segmented(
                $breakdowns[2] ?? [],
                $breakdowns[3] ?? [],
                'region',
                fn (string $value) => Region::forCountry($value)->value,
            ),
        ];
    }

    /** All the traffic, so a conversion rate has a denominator. */
    private function sessionsBy(string $dimension): array
    {
        return [
            'metrics' => [['name' => 'sessions']],
            'dimensions' => [['name' => 'date'], ['name' => $dimension]],
        ];
    }

    /** Only the signups, counted separately for the same reason. */
    private function leadsBy(string $dimension): array
    {
        return [
            'metrics' => [['name' => 'eventCount']],
            'dimensions' => [['name' => 'date'], ['name' => $dimension]],
            'dimensionFilter' => $this->onlyLeadEvents(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>
     */
    private function batch(string $token, string $property, array $requests): array
    {
        $range = [['startDate' => self::LOOKBACK.'daysAgo', 'endDate' => 'yesterday']];

        return Http::withToken($token)
            ->post(
                "https://analyticsdata.googleapis.com/v1beta/properties/{$property}:batchRunReports",
                ['requests' => array_map(
                    fn (array $request) => ['dateRanges' => $range] + $request,
                    $requests,
                )],
            )->throw()->json('reports', []);
    }

    /**
     * GA4 names the signup event differently depending on how it was set
     * up, so match the handful that mean the same thing rather than
     * insisting on one and reporting zero for everybody else.
     */
    private function onlyLeadEvents(): array
    {
        return [
            'filter' => [
                'fieldName' => 'eventName',
                'inListFilter' => ['values' => self::LEAD_EVENTS],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function traffic(array $report): array
    {
        $names = ['sessions', 'users', 'pageviews'];
        $rows = [];

        foreach ($report['rows'] ?? [] as $row) {
            $date = $this->dateOf($row, 0);

            foreach ($row['metricValues'] ?? [] as $i => $value) {
                if (isset($names[$i])) {
                    $rows[] = [
                        'metric' => $names[$i],
                        'value' => (float) $value['value'],
                        'recorded_at' => $date,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Signups per day, summed across whatever the event is called. Two
     * event names both in use would otherwise arrive as two rows for one
     * day and the later would win.
     *
     * @return list<array<string, mixed>>
     */
    private function leads(array $report): array
    {
        $perDay = [];

        foreach ($report['rows'] ?? [] as $row) {
            $date = $this->dateOf($row, 0);
            $perDay[$date] = ($perDay[$date] ?? 0.0) + (float) ($row['metricValues'][0]['value'] ?? 0);
        }

        return array_map(
            fn (string $date, float $value) => [
                'metric' => 'site_leads',
                'value' => $value,
                'recorded_at' => $date,
            ],
            array_keys($perDay),
            $perDay,
        );
    }

    /**
     * Joins a sessions report and a signups report on (date, segment).
     *
     * Both are keyed the same way, so a segment appearing in only one of
     * them still produces a row — traffic that never converted is the
     * most useful row in the table.
     *
     * @param  callable(string): string  $bucket
     * @return list<array<string, mixed>>
     */
    private function segmented(array $sessions, array $leads, string $key, callable $bucket): array
    {
        $totals = [];

        foreach ($this->tally($sessions, $bucket) as $composite => $value) {
            $totals[$composite]['sessions'] = $value;
        }

        foreach ($this->tally($leads, $bucket) as $composite => $value) {
            $totals[$composite]['leads'] = $value;
        }

        $rows = [];

        foreach ($totals as $composite => $figures) {
            [$date, $segment] = explode('|', $composite, 2);
            $dimensions = [$key => $segment];

            $rows[] = [
                'metric' => "sessions_by_{$key}",
                'value' => $figures['sessions'] ?? 0.0,
                'recorded_at' => $date,
                'dimensions' => $dimensions,
            ];

            $rows[] = [
                'metric' => "leads_by_{$key}",
                'value' => $figures['leads'] ?? 0.0,
                'recorded_at' => $date,
                'dimensions' => $dimensions,
            ];
        }

        return $rows;
    }

    /**
     * Sums a two-dimension report into date|segment keys. Summing matters
     * because bucketing collapses many countries into one region, and
     * those rows have to add up rather than overwrite each other.
     *
     * @param  callable(string): string  $bucket
     * @return array<string, float>
     */
    private function tally(array $report, callable $bucket): array
    {
        $totals = [];

        foreach ($report['rows'] ?? [] as $row) {
            $composite = $this->dateOf($row, 0).'|'.$bucket((string) ($row['dimensionValues'][1]['value'] ?? ''));

            $totals[$composite] = ($totals[$composite] ?? 0.0)
                + (float) ($row['metricValues'][0]['value'] ?? 0);
        }

        return $totals;
    }

    private function dateOf(array $row, int $index): string
    {
        $date = $row['dimensionValues'][$index]['value'] ?? now()->format('Ymd');

        return substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);
    }
}
