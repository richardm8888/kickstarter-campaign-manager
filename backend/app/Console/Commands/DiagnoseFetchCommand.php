<?php

namespace App\Console\Commands;

use App\Support\BrowserHeaders;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Works out why a page will not fetch.
 *
 * There are two very different reasons a site returns 403 to us, and they
 * have opposite fixes: either our request does not look enough like a
 * browser, or the host has decided this server's IP is a robot regardless
 * of what it sends. Sending progressively more browser-like requests from
 * the machine that is actually being refused separates the two in one run,
 * which guessing from a development box cannot do.
 */
class DiagnoseFetchCommand extends Command
{
    protected $signature = 'page:diagnose {url : The page that will not fetch}';

    protected $description = 'Report why a URL is refusing our requests';

    public function handle(): int
    {
        $url = $this->argument('url');

        $this->components->info("Fetching {$url} three ways");

        $results = [];

        foreach ($this->profiles() as $name => $headers) {
            $results[$name] = $this->attempt($name, $url, $headers);
        }

        $this->newLine();
        $this->verdict($results);

        return self::SUCCESS;
    }

    /** @return array<string, array<string, string>> */
    private function profiles(): array
    {
        $browser = BrowserHeaders::get();

        return [
            // Establishes the baseline: what a bare HTTP client gets.
            'no headers' => [],

            'what we send now' => $browser,

            // Client hints are what a current Chrome adds on top. If only
            // this one succeeds, the header set is simply incomplete.
            'browser + client hints' => $browser + [
                'sec-ch-ua' => '"Chromium";v="128", "Not(A:Brand";v="24", "Google Chrome";v="128"',
                'sec-ch-ua-mobile' => '?0',
                'sec-ch-ua-platform' => '"macOS"',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Referer' => 'https://www.google.com/',
            ],
        ];
    }

    /** @return array{status: int|null, note: string} */
    private function attempt(string $name, string $url, array $headers): array
    {
        try {
            $response = Http::withHeaders($headers)->timeout(20)->get($url);
        } catch (RequestException $e) {
            // A refusal that arrives as an exception is still a refusal;
            // dropping its status would have the verdict call an outright
            // block a connectivity problem.
            $status = $e->response->status();
            $this->components->twoColumnDetail($name, "<fg=red>HTTP {$status}</> · threw");

            return ['status' => $status, 'note' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->components->twoColumnDetail($name, '<fg=red>could not connect</>');
            $this->line('    '.$e->getMessage());

            return ['status' => null, 'note' => $e->getMessage()];
        }

        $status = $response->status();
        $size = strlen($response->body());
        $colour = $response->successful() ? 'green' : 'red';

        $this->components->twoColumnDetail(
            $name,
            "<fg={$colour}>HTTP {$status}</> · ".number_format($size).' bytes',
        );

        // Which system refused matters: Cloudflare blocks on IP reputation
        // and TLS fingerprint, neither of which more headers can fix.
        foreach (['server', 'cf-ray', 'cf-mitigated', 'retry-after'] as $header) {
            if ($value = $response->header($header)) {
                $this->line("    {$header}: {$value}");
            }
        }

        return ['status' => $status, 'note' => ''];
    }

    /** @param  array<string, array{status: int|null, note: string}>  $results */
    private function verdict(array $results): void
    {
        $statuses = array_column($results, 'status');
        $anyOk = array_filter($statuses, fn (?int $s) => $s !== null && $s >= 200 && $s < 300);

        if ($anyOk !== []) {
            $this->components->info(
                'A header profile got through. The fix is in our headers — '
                .'tell Claude which profile above returned 200.',
            );

            return;
        }

        if (in_array(403, $statuses, true) || in_array(429, $statuses, true)) {
            $this->components->warn(
                'Every profile was refused. That points at this server rather than '
                .'the request: the host is judging the IP or the TLS fingerprint, '
                .'and no amount of header work will change it. Run the same URL '
                .'from your laptop to confirm — if it loads there and not here, '
                .'it is the droplet.',
            );

            return;
        }

        $this->components->warn('No profile succeeded, and not with a block status either. '
            .'Check the statuses above — this may be DNS or egress from this host.');
    }
}
