<?php

namespace App\Services\PageAudit;

use App\Support\BrowserHeaders;
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

    /**
     * Being turned away is not a fact about the creator's page, so we say
     * so rather than storing a near-zero score they cannot act on: every
     * content check fails when there is no content to read.
     */
    private const BLOCKED = [401, 403, 429, 451];

    public function fetch(string $url): FetchedPage
    {
        PublicUrl::assertSafe($url);

        $startedAt = microtime(true);
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::withoutRedirecting()
                    ->timeout(self::TIMEOUT)
                    ->withHeaders(BrowserHeaders::get())
                    ->get($current);
            } catch (Throwable $e) {
                throw new RuntimeException("Could not reach that URL: {$e->getMessage()}");
            }

            if (in_array($response->status(), self::BLOCKED, true)) {
                throw new RuntimeException(
                    'That page refused our request (HTTP '.$response->status().'). '
                    .'It is up — the site is blocking automated readers, not you. '
                    .'Try again in a few minutes.',
                );
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
