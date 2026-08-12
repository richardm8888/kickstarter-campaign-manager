<?php

namespace App\Services\Ads;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Asks Meta which shapes of pixel event data it will actually return.
 *
 * The question this settles: can we read *all* pixel events, or only the
 * ones Meta attributed to an ad? It matters because a follow that came
 * from an email fires the pixel on the Kickstarter page but was never an
 * ad click, so Meta attributes it to nothing and it is invisible in ad
 * reporting. If the total is readable, subtracting the attributed ones
 * leaves the follows nobody paid for.
 *
 * Meta removes edges quietly, so this asks the live API with the
 * creator's own token rather than trusting anyone's recollection. The
 * error text comes back verbatim: "this edge is gone" and "your token
 * lacks a permission" need different responses and read identically once
 * paraphrased.
 */
class MetaPixelProbe
{
    /**
     * @return array{pixels: list<array<string, mixed>>, checked_at: string}
     *
     * @throws RuntimeException when there is nothing to probe with
     */
    public function run(Project $project): array
    {
        $integration = $project->integrations()
            ->where('provider', 'meta')
            ->where('status', Integration::STATUS_CONNECTED)
            ->first();

        $token = $integration?->credentials['access_token'] ?? null;
        $account = ltrim((string) ($integration?->credentials['ad_account_id'] ?? ''), 'act_');

        if ($token === null || $account === '') {
            throw new RuntimeException(
                'Meta is not connected, or its saved credentials are incomplete. Reconnect it on the Integrations page.',
            );
        }

        $version = config('services.meta.api_version');
        $pixels = [];

        foreach ($this->pixels($version, $account, $token) as $pixel) {
            $id = (string) ($pixel['id'] ?? '');

            $checks = [];

            foreach ($this->edges($id) as $label => $request) {
                $checks[] = $this->attempt($version, $token, $label, $request);
            }

            $pixels[] = [
                'id' => $id,
                'name' => $pixel['name'] ?? 'Unnamed pixel',
                'last_fired_at' => $pixel['last_fired_time'] ?? null,
                'checks' => $checks,
            ];
        }

        return ['pixels' => $pixels, 'checked_at' => now()->toIso8601String()];
    }

    /**
     * The candidate ways of asking. Each has existed at some point; which
     * survive in this API version is the whole question.
     *
     * @return array<string, array{path: string, query: array<string, mixed>}>
     */
    private function edges(string $pixelId): array
    {
        $since = now()->subDays(30)->timestamp;
        $until = now()->timestamp;
        $window = ['start_time' => $since, 'end_time' => $until];

        return [
            'Totals by event' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'event'] + $window,
            ],
            'Totals by domain' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'host'] + $window,
            ],
            'Totals by page' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'url'] + $window,
            ],
            'Pixel details' => [
                'path' => $pixelId,
                'query' => ['fields' => 'id,name,last_fired_time,is_unavailable'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pixels(string $version, string $account, string $token): array
    {
        try {
            return Http::timeout(20)
                ->get("https://graph.facebook.com/{$version}/act_{$account}/adspixels", [
                    'access_token' => $token,
                    'fields' => 'id,name,last_fired_time',
                    'limit' => 25,
                ])
                ->throw()
                ->json('data', []);
        } catch (Throwable $e) {
            throw new RuntimeException('Could not list pixels on that ad account: '.$e->getMessage());
        }
    }

    /**
     * @param  array{path: string, query: array<string, mixed>}  $request
     * @return array{label: string, ok: bool, rows: int, detail: ?string, sample: ?string}
     */
    private function attempt(string $version, string $token, string $label, array $request): array
    {
        try {
            $response = Http::timeout(20)->get(
                "https://graph.facebook.com/{$version}/{$request['path']}",
                $request['query'] + ['access_token' => $token],
            );
        } catch (Throwable $e) {
            return $this->result($label, false, 0, $e->getMessage());
        }

        $body = $response->json() ?? [];

        if (isset($body['error'])) {
            // Meta's own words. Paraphrasing loses the distinction between
            // an edge that no longer exists and one this token may not use,
            // and those have opposite fixes.
            return $this->result($label, false, 0, $body['error']['message'] ?? 'Unknown error');
        }

        $rows = $body['data'] ?? [];

        return $this->result(
            $label,
            true,
            count($rows),
            null,
            // One row of shape, so the fields can be read without guessing.
            $rows === [] ? null : mb_substr((string) json_encode($rows[0]), 0, 300),
        );
    }

    /** @return array{label: string, ok: bool, rows: int, detail: ?string, sample: ?string} */
    private function result(string $label, bool $ok, int $rows, ?string $detail = null, ?string $sample = null): array
    {
        return [
            'label' => $label,
            'ok' => $ok,
            'rows' => $rows,
            'detail' => $detail,
            'sample' => $sample,
        ];
    }
}
