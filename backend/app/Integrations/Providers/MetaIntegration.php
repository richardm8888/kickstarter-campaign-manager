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

    protected function fetchMetrics(array $credentials): array
    {
        // Tolerate an ad account id pasted straight from Ads Manager.
        $accountId = ltrim($credentials['ad_account_id'], 'act_');
        $version = config('services.meta.api_version');

        $response = Http::baseUrl("https://graph.facebook.com/{$version}")
            ->get("/act_{$accountId}/insights", [
                'access_token' => $credentials['access_token'],
                'date_preset' => 'last_7d',
                'time_increment' => 1,
                'fields' => 'spend,impressions,clicks,cpc,cpm,ctr',
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
