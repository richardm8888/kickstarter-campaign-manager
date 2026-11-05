<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use App\Integrations\Support\GoogleServiceAccount;
use App\Services\Seo\SearchConsoleClient;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Google Search Console: how the project's website does in search.
 *
 * The sync stores daily totals so search can sit beside the rest of the
 * funnel. The detail — which searches, which pages — is read live by the
 * SEO page, because it changes shape every time and is only worth
 * fetching when someone is looking at it.
 */
class SearchConsoleIntegration extends BaseIntegration
{
    private const LOOKBACK = 90;

    public function provider(): string
    {
        return 'search_console';
    }

    public function displayName(): string
    {
        return 'Google Search Console';
    }

    public function credentialFields(): array
    {
        return [
            'site_url' => [
                'label' => 'Property',
                'help' => 'As Search Console names it: sc-domain:yoursite.com for a domain property, or the full https:// address for a URL-prefix property.',
                'type' => 'text',
                'placeholder' => 'sc-domain:yoursite.com',
            ],
            'service_account_json' => [
                'label' => 'Service account key (JSON)',
                'help' => 'The same kind of key as GA4 (you can reuse it). Enable the Search Console API in Google Cloud, then in Search Console → Settings → Users and permissions add the key\'s client_email with Restricted access.',
                'type' => 'textarea',
                'placeholder' => '{"type": "service_account", ...}',
            ],
        ];
    }

    public function docsUrl(): ?string
    {
        return 'https://developers.google.com/webmaster-tools/v1/how-tos/search_analytics';
    }

    protected function validateCredentials(array $credentials): void
    {
        $site = trim($credentials['site_url']);

        if (! str_starts_with($site, 'sc-domain:') && ! preg_match('#^https?://#', $site)) {
            throw ValidationException::withMessages([
                'credentials' => ['The property should look like sc-domain:yoursite.com or https://www.yoursite.com/.'],
            ]);
        }

        try {
            GoogleServiceAccount::parseKey($credentials['service_account_json']);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['credentials' => [$e->getMessage()]]);
        }
    }

    protected function fetchMetrics(array $credentials): array
    {
        $end = Carbon::today()->subDays(SearchConsoleClient::LAG_DAYS);

        $rows = app(SearchConsoleClient::class)->query(
            $credentials,
            $end->copy()->subDays(self::LOOKBACK - 1)->toDateString(),
            $end->toDateString(),
            ['date'],
            self::LOOKBACK,
        );

        $metrics = [];

        foreach ($rows as $row) {
            $day = Carbon::parse($row['keys'][0] ?? null)->startOfDay();

            foreach (['clicks', 'impressions', 'ctr', 'position'] as $name) {
                $metrics[] = [
                    'metric' => "search_{$name}",
                    'value' => $name === 'ctr' ? round($row[$name] * 100, 2) : $row[$name],
                    'recorded_at' => $day,
                ];
            }
        }

        return $metrics;
    }
}
