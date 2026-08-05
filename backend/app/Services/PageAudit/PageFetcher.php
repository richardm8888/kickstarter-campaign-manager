<?php

namespace App\Services\PageAudit;

use App\Support\PublicUrl;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Fetches a creator-supplied URL safely, following redirects by hand so
 * every hop is re-checked against the SSRF guard rather than trusting the
 * first one.
 */
class PageFetcher
{
    private const TIMEOUT = 15;

    private const MAX_REDIRECTS = 3;

    public function fetch(string $url): FetchedPage
    {
        PublicUrl::assertSafe($url);

        $startedAt = microtime(true);
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::withoutRedirecting()
                    ->timeout(self::TIMEOUT)
                    ->withHeaders([
                        // Some hosts serve a stripped page to unknown clients.
                        'User-Agent' => 'Mozilla/5.0 (compatible; KickstarterLaunchOS/1.0; page auditor)',
                        'Accept' => 'text/html,application/xhtml+xml',
                    ])
                    ->get($current);
            } catch (Throwable $e) {
                throw new RuntimeException("Could not reach that URL: {$e->getMessage()}");
            }

            if (! $response->redirect()) {
                return new FetchedPage(
                    html: $response->body(),
                    url: $current,
                    status: $response->status(),
                    elapsedMs: (int) ((microtime(true) - $startedAt) * 1000),
                );
            }

            $location = $response->header('Location');

            if ($location === '') {
                throw new RuntimeException('That URL redirected without a destination.');
            }

            // Redirect targets are user-influenced too, so re-check each hop.
            $current = str_starts_with($location, 'http')
                ? $location
                : rtrim($current, '/').'/'.ltrim($location, '/');

            PublicUrl::assertSafe($current);
        }

        throw new RuntimeException('That URL redirected too many times.');
    }
}
