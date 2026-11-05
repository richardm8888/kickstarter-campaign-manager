<?php

namespace App\Services\Seo;

use App\Integrations\Support\GoogleServiceAccount;
use Illuminate\Support\Facades\Http;

/**
 * The one call this app makes to Search Console: the search analytics
 * query, with the service account's token.
 */
class SearchConsoleClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

    /** Search Console's data lags; the last couple of days are incomplete. */
    public const LAG_DAYS = 2;

    public function __construct(private readonly GoogleServiceAccount $google) {}

    /**
     * @param  list<string>  $dimensions  e.g. ['query'], ['query', 'page'], ['date']
     * @return list<array{keys: list<string>, clicks: float, impressions: float, ctr: float, position: float}>
     */
    public function query(array $credentials, string $startDate, string $endDate, array $dimensions, int $rowLimit = 1000): array
    {
        $key = GoogleServiceAccount::parseKey($credentials['service_account_json']);
        $token = $this->google->accessToken($key, self::SCOPE);
        $site = rawurlencode(trim($credentials['site_url']));

        $rows = Http::withToken($token)
            ->timeout(30)
            ->post("https://www.googleapis.com/webmasters/v3/sites/{$site}/searchAnalytics/query", [
                'startDate' => $startDate,
                'endDate' => $endDate,
                'dimensions' => $dimensions,
                'rowLimit' => $rowLimit,
                'dataState' => 'final',
            ])
            ->throw()
            ->json('rows') ?? [];

        return array_map(fn (array $row) => [
            'keys' => $row['keys'] ?? [],
            'clicks' => (float) ($row['clicks'] ?? 0),
            'impressions' => (float) ($row['impressions'] ?? 0),
            'ctr' => (float) ($row['ctr'] ?? 0),
            'position' => (float) ($row['position'] ?? 0),
        ], $rows);
    }
}
