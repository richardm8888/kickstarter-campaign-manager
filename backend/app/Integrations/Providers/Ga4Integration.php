<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use App\Integrations\Support\GoogleServiceAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class Ga4Integration extends BaseIntegration
{
    private const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

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

    protected function fetchMetrics(array $credentials): array
    {
        $key = GoogleServiceAccount::parseKey($credentials['service_account_json']);
        $token = app(GoogleServiceAccount::class)->accessToken($key, self::SCOPE);

        $response = Http::withToken($token)
            ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$credentials['property_id']}:runReport", [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'yesterday']],
                'metrics' => [
                    ['name' => 'sessions'],
                    ['name' => 'totalUsers'],
                    ['name' => 'screenPageViews'],
                ],
                'dimensions' => [['name' => 'date']],
            ])->throw()->json('rows', []);

        $names = ['sessions', 'users', 'pageviews'];
        $rows = [];

        foreach ($response as $row) {
            $date = $row['dimensionValues'][0]['value'] ?? now()->format('Ymd');
            $recordedAt = substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);

            foreach ($row['metricValues'] ?? [] as $i => $value) {
                if (isset($names[$i])) {
                    $rows[] = [
                        'metric' => $names[$i],
                        'value' => (float) $value['value'],
                        'recorded_at' => $recordedAt,
                    ];
                }
            }
        }

        return $rows;
    }
}
