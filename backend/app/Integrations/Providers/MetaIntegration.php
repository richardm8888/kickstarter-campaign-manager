<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;

class MetaIntegration extends BaseIntegration
{
    public function provider(): string
    {
        return 'meta';
    }

    public function displayName(): string
    {
        return 'Meta Ads';
    }

    public function requiredCredentials(): array
    {
        return ['access_token', 'ad_account_id'];
    }

    protected function fetchMetrics(array $credentials): array
    {
        $response = Http::baseUrl('https://graph.facebook.com/v21.0')
            ->get("/act_{$credentials['ad_account_id']}/insights", [
                'access_token' => $credentials['access_token'],
                'date_preset' => 'yesterday',
                'fields' => 'spend,impressions,clicks,cpc,cpm,ctr,actions',
            ])->throw()->json('data', []);

        $rows = [];

        foreach ($response as $day) {
            $recordedAt = $day['date_stop'] ?? now()->toDateString();

            foreach (['spend', 'impressions', 'clicks', 'cpc', 'cpm', 'ctr'] as $metric) {
                if (isset($day[$metric])) {
                    $rows[] = [
                        'metric' => $metric,
                        'value' => (float) $day[$metric],
                        'recorded_at' => $recordedAt,
                    ];
                }
            }
        }

        return $rows;
    }
}
