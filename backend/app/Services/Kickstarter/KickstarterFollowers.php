<?php

namespace App\Services\Kickstarter;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use App\Support\BrowserHeaders;
use App\Support\PublicUrl;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Reads the follower count off a Kickstarter pre-launch page.
 *
 * Followers are the single most valuable pre-launch audience a creator can
 * build — Kickstarter emails them the instant the campaign opens — but
 * there is no API for the count, so the public page is the only source.
 *
 * The page's markup is not a contract and changes without notice, so
 * several shapes are tried and a failure is reported rather than guessed
 * at. A stale count is far better than an invented one.
 */
class KickstarterFollowers
{
    /**
     * Only Kickstarter pages, so this cannot be pointed at an arbitrary
     * host to be fetched by the server on a schedule.
     */
    private const ALLOWED_HOSTS = ['kickstarter.com', 'www.kickstarter.com'];

    private const GRAPH_URL = 'https://www.kickstarter.com/graph';

    public function __construct(private readonly MetricRecorder $recorder) {}

    /** Records the follower count and returns it, or null when unreadable. */
    public function sync(Project $project): ?int
    {
        if ($project->kickstarter_url === null) {
            return null;
        }

        try {
            $count = $this->fetch($project->kickstarter_url);
        } catch (InvalidArgumentException $e) {
            Log::warning('Kickstarter follower sync rejected a URL', [
                'project_id' => $project->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        } catch (\Throwable $e) {
            Log::warning('Kickstarter follower sync failed', [
                'project_id' => $project->id,
                'reason' => $e->getMessage(),
            ]);

            return null;
        }

        if ($count === null) {
            return null;
        }

        $this->recorder->record($project, 'kickstarter', 'ks_followers', $count);

        return $count;
    }

    /** @throws InvalidArgumentException when the URL is not a usable Kickstarter page */
    public function fetch(string $url): ?int
    {
        $this->assertKickstarterUrl($url);

        // The page is a shell: React fetches the count after load, so it
        // is never in the HTML however well we read it. What the page
        // does is ask GraphQL, and so do we — the visit is only here to
        // collect the session cookies and CSRF token that call needs.
        $jar = new CookieJar;

        $response = Http::withHeaders(BrowserHeaders::get())
            ->withOptions(['cookies' => $jar])
            ->timeout(15)->retry(2, 500)->get($url);

        if (! $response->successful()) {
            return null;
        }

        // The HTML fallback stays for any page that does render a count,
        // and for the day the API shape moves under us.
        return $this->fromGraph($url, $response->body(), $jar) ?? $this->extract($response->body());
    }

    /**
     * Ask the endpoint the pre-launch page itself uses.
     *
     * Kickstarter's word for a follower is a "watch", so the field is
     * watchesCount — taken from the page's own PrelaunchPage query rather
     * than guessed. No login is involved: an anonymous visit hands out
     * everything this needs, and `me` simply comes back null.
     */
    private function fromGraph(string $url, string $html, CookieJar $jar): ?int
    {
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/i', $html, $matches) !== 1) {
            return null;
        }

        try {
            $response = Http::withHeaders(array_merge(BrowserHeaders::get(), [
                'x-csrf-token' => $matches[1],
                'content-type' => 'application/json',
                'accept' => '*/*',
                // A same-origin XHR, which is what this is pretending to
                // be. The navigation headers would contradict that.
                'Referer' => $url,
                'Sec-Fetch-Dest' => 'empty',
                'Sec-Fetch-Mode' => 'cors',
                'Sec-Fetch-Site' => 'same-origin',
            ]))
                ->withOptions(['cookies' => $jar])
                ->timeout(15)
                // Batched in an array, because that is how the page sends
                // it and matching the real client is what got us through
                // Cloudflare in the first place.
                ->post(self::GRAPH_URL, [[
                    'operationName' => 'PrelaunchPage',
                    'variables' => ['slug' => $this->slug($url)],
                    'query' => 'query PrelaunchPage($slug: String!) '
                        .'{ project(slug: $slug) { watchesCount state } }',
                ]]);
        } catch (\Throwable $e) {
            Log::warning('Kickstarter GraphQL call failed', ['reason' => $e->getMessage()]);

            return null;
        }

        $body = $response->json();
        // A batched request answers with a list; a plain one does not.
        $payload = array_is_list($body ?? []) ? ($body[0] ?? []) : ($body ?? []);
        $count = $payload['data']['project']['watchesCount'] ?? null;

        return is_numeric($count) ? (int) $count : null;
    }

    /** GraphQL identifies a project by its creator/project path. */
    private function slug(string $url): string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return (string) preg_replace('#^projects/#', '', $path);
    }

    /**
     * Pull the count out of the page.
     *
     * Ordered most to least trustworthy: embedded JSON state first, then
     * data attributes, then visible text. Text is last because "1.2K
     * followers" is lossy and any number on the page could match.
     */
    public function extract(string $html): ?int
    {
        $patterns = [
            // What a pre-launch page actually renders. A test id is not a
            // contract either, but it is the most stable handle on the
            // page and it changes far less often than class names.
            '/data-test-id="followers-count"[^>]*>\s*([\d,.]+\s*[KM]?)/i',
            '/"follower[_s]?count"\s*:\s*"?([\d,.]+\s*[KM]?)"?/i',
            '/"followers"\s*:\s*"?([\d,.]+\s*[KM]?)"?/i',
            '/data-follower[s]?-count\s*=\s*"([\d,.]+\s*[KM]?)"/i',
            // The number in its own element: <span>2,048</span> followers.
            '/>\s*([\d,.]+\s*[KM]?)\s*<[^>]*>?\s*followers/i',
            // Last, and loosest: any number immediately before the word.
            '/\b([\d,.]+\s*[KM]?)\s+followers\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return $this->toCount($matches[1]);
            }
        }

        return null;
    }

    /**
     * Kickstarter abbreviates large counts, so "1.2K" has to mean 1,200
     * rather than 1. Abbreviated figures are lossy by nature — 1.2K is
     * anything from 1,150 to 1,249 — but a rounded count beats none.
     */
    private function toCount(string $raw): ?int
    {
        $value = str_replace([',', ' '], '', trim($raw));

        $multiplier = match (strtoupper(substr($value, -1))) {
            'K' => 1_000,
            'M' => 1_000_000,
            default => 1,
        };

        if ($multiplier > 1) {
            $value = substr($value, 0, -1);
        }

        return is_numeric($value) ? (int) round((float) $value * $multiplier) : null;
    }

    private function assertKickstarterUrl(string $url): void
    {
        PublicUrl::assertSafe($url);

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidArgumentException('That is not a kickstarter.com URL.');
        }
    }

    /** Whether a URL would be accepted, for validating the settings form. */
    public static function isValidUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, self::ALLOWED_HOSTS, true);
    }
}
