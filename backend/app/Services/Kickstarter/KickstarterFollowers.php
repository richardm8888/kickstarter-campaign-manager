<?php

namespace App\Services\Kickstarter;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use App\Support\PublicUrl;
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

        $response = Http::withHeaders([
            // Kickstarter serves a stripped page to unrecognised clients.
            'User-Agent' => 'Mozilla/5.0 (compatible; LaunchOS/1.0; +https://github.com/richardm8888/kickstarter-campaign-manager)',
            'Accept' => 'text/html,application/xhtml+xml',
        ])->timeout(15)->retry(2, 500)->get($url);

        if (! $response->successful()) {
            return null;
        }

        return $this->extract($response->body());
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
            '/"follower[_s]?count"\s*:\s*"?(\d[\d,]*)"?/i',
            '/"followers"\s*:\s*"?(\d[\d,]*)"?/i',
            '/data-follower[s]?-count\s*=\s*"(\d[\d,]*)"/i',
            '/>\s*(\d[\d,]*)\s*<[^>]*>?\s*followers/i',
            '/\b(\d[\d,]*)\s+followers\b/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return (int) str_replace(',', '', $matches[1]);
            }
        }

        return null;
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
