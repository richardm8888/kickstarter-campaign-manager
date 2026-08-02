<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;

class Ga4Integration extends BaseIntegration
{
    public function provider(): string
    {
        return 'ga4';
    }

    public function displayName(): string
    {
        return 'Google Analytics 4';
    }

    public function requiredCredentials(): array
    {
        return ['property_id', 'access_token'];
    }

    protected function fetchMetrics(array $credentials): array
    {
        $response = Http::withToken($credentials['access_token'])
            ->post("https://analyticsdata.googleapis.com/v1beta/properties/{$credentials['property_id']}:runReport", [
                'dateRanges' => [['startDate' => 'yesterday', 'endDate' => 'yesterday']],
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
