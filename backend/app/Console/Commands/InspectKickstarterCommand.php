<?php

namespace App\Console\Commands;

use App\Support\BrowserHeaders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Shows what a Kickstarter page actually says about its audience.
 *
 * Kickstarter publishes no API and changes its markup without notice, so
 * the patterns we match are guesses until someone looks at a real page.
 * This prints the evidence — every mention of following, backing and the
 * embedded JSON keys around them — so a pattern can be written from what
 * is there rather than from what we expect to be there.
 *
 * It is also the honest way to find out that a number is simply not
 * public, which is the answer for follower counts on some page types.
 */
class InspectKickstarterCommand extends Command
{
    protected $signature = 'kickstarter:inspect
        {url : The project or pre-launch page}
        {--save= : Write the fetched HTML here for a closer look}';

    protected $description = 'Show what audience numbers a Kickstarter page exposes';

    /** Enough either side to see the shape of the surrounding markup. */
    private const CONTEXT = 100;

    private const MAX_SNIPPETS = 12;

    public function handle(): int
    {
        $response = Http::withHeaders(BrowserHeaders::get())->timeout(20)->get($this->argument('url'));

        if (! $response->successful()) {
            $this->components->error("HTTP {$response->status()} — run page:diagnose first.");

            return self::FAILURE;
        }

        $html = $response->body();
        $this->components->info(number_format(strlen($html)).' bytes fetched');

        if ($path = $this->option('save')) {
            file_put_contents($path, $html);
            $this->components->twoColumnDetail('saved to', $path);
        }

        $this->reportPageKind($html);
        $this->reportKeys($html);
        $this->reportMentions($html);

        return self::SUCCESS;
    }

    /**
     * A live campaign and a pre-launch page expose different numbers, and
     * asking the wrong one for followers is not a markup problem.
     */
    private function reportPageKind(string $html): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>page looks like</>', match (true) {
            str_contains($html, 'data-project-state="live"') => 'a live campaign',
            (bool) preg_match('/coming soon|notify me on launch/i', $html) => 'a pre-launch page',
            default => 'unrecognised — check the saved HTML',
        });
    }

    /** The JSON keys worth matching on, if they are present at all. */
    private function reportKeys(string $html): void
    {
        $this->newLine();
        $this->line(' <fg=cyan>Embedded JSON keys</>');

        $keys = ['follower', 'followers_count', 'backers_count', 'pledged',
            'goal', 'launched_at', 'is_following', 'prelaunch_activated'];

        foreach ($keys as $key) {
            $found = preg_match_all('/"'.preg_quote($key, '/').'"\s*:\s*([^,}]{0,40})/i', $html, $m);

            $this->components->twoColumnDetail(
                "  {$key}",
                $found === 0 ? '<fg=gray>absent</>' : '<fg=green>'.trim($m[1][0]).'</>',
            );
        }
    }

    /**
     * Every visible mention, best first.
     *
     * Ranking is the whole point. Kickstarter ships a feature-flag blob at
     * the top of the document containing dozens of numbered names like
     * `backer_report_update_2024`, and taking mentions in document order
     * let that noise exhaust the cap before the scan reached the body —
     * which hid a perfectly ordinary "19 followers" and had us conclude
     * the count was not published at all. A truncated search is not
     * evidence of absence, so what survives truncation must be the
     * strongest candidates rather than merely the earliest.
     */
    private function reportMentions(string $html): void
    {
        $this->newLine();
        $this->line(' <fg=cyan>Mentions of following / backers</>');

        preg_match_all('/follow(?:er|ing)?s?|backers?/i', $html, $m, PREG_OFFSET_CAPTURE);

        $snippets = [];

        foreach ($m[0] as [, $offset]) {
            $snippet = trim(preg_replace(
                '/\s+/', ' ',
                substr($html, max(0, $offset - self::CONTEXT), self::CONTEXT * 2),
            ));

            if (preg_match('/\d/', $snippet) === 1) {
                $snippets[$snippet] = $this->rank($snippet);
            }
        }

        if ($snippets === []) {
            $this->components->warn('No mention carries a number. The count may not be public.');

            return;
        }

        arsort($snippets);
        $shown = array_slice($snippets, 0, self::MAX_SNIPPETS, true);

        foreach ($shown as $snippet => $rank) {
            $this->line(($rank > 0 ? '  <fg=green>▸</> ' : '  … ').$snippet.' …');
        }

        if (count($snippets) > count($shown)) {
            $this->line('  <fg=gray>('.(count($snippets) - count($shown)).' lower-ranked suppressed)</>');
        }
    }

    /** Higher means more likely to be a real audience figure. */
    private function rank(string $snippet): int
    {
        return match (true) {
            (bool) preg_match('/data-test-id="[^"]*(?:follower|backer)[^"]*"/i', $snippet) => 3,
            (bool) preg_match('/\b[\d,.]+\s*[KM]?\s+(?:followers?|backers?)\b/i', $snippet) => 2,
            (bool) preg_match('/"(?:follower|backer)[a-z_]*"\s*:\s*"?\d/i', $snippet) => 1,
            default => 0,
        };
    }
}
