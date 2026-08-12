<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Asks Meta what it will tell us about a pixel's events.
 *
 * The question this exists to settle: can we read *all* pixel events, or
 * only the ones Meta attributed to an ad? It matters because a follow
 * that came from an email fires the pixel on the Kickstarter page but was
 * never an ad click, so Meta attributes it to nothing and it is invisible
 * in ad reporting. If the total is readable, subtracting the attributed
 * ones leaves the follows nobody paid for — the number this whole funnel
 * has been trying to infer.
 *
 * Meta deprecates edges quietly and their docs are not reachable from
 * every network, so this asks the live API with the creator's own token
 * rather than trusting anyone's recollection of what exists.
 */
class ProbeMetaPixelCommand extends Command
{
    protected $signature = 'meta:pixel-probe {--project= : Which project\'s credentials to use}';

    protected $description = 'Find out which pixel event data Meta will actually return';

    public function handle(): int
    {
        $integration = Integration::query()
            ->where('provider', 'meta')
            ->where('status', Integration::STATUS_CONNECTED)
            ->when($this->option('project'), fn ($q, $id) => $q->where('project_id', $id))
            ->first();

        if ($integration?->credentials === null) {
            $this->components->error('No connected Meta integration. Connect one first.');

            return self::FAILURE;
        }

        $this->components->info(
            'Using '.(Project::find($integration->project_id)?->name ?? 'project '.$integration->project_id)
        );

        $token = $integration->credentials['access_token'] ?? null;
        $account = ltrim((string) ($integration->credentials['ad_account_id'] ?? ''), 'act_');

        if ($token === null || $account === '') {
            $this->components->error(
                'That Meta connection is missing an access token or ad account id. Reconnect it on the Integrations page.',
            );

            return self::FAILURE;
        }
        $version = config('services.meta.api_version');

        $pixels = $this->pixels($version, $account, $token);

        if ($pixels === []) {
            $this->components->warn(
                'No pixels on this ad account. Follows are being recorded, so the pixel '
                .'may belong to a different account than the one advertising.',
            );

            return self::SUCCESS;
        }

        foreach ($pixels as $pixel) {
            $this->newLine();
            $this->components->twoColumnDetail(
                '<fg=cyan>'.($pixel['name'] ?? 'Unnamed').'</>',
                $pixel['id'].' · last fired '.($pixel['last_fired_time'] ?? 'never'),
            );

            foreach ($this->edges($pixel['id']) as $label => $request) {
                $this->attempt($version, $token, $label, $request);
            }
        }

        $this->newLine();
        $this->components->info(
            'Send Claude everything above. A green line means that shape of event data is '
            .'readable, and unattributed follows can be counted.',
        );

        return self::SUCCESS;
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

        return [
            'stats by event' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'event', 'start_time' => $since, 'end_time' => $until],
            ],
            'stats by host' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'host', 'start_time' => $since, 'end_time' => $until],
            ],
            'stats by url' => [
                'path' => "{$pixelId}/stats",
                'query' => ['aggregation' => 'url', 'start_time' => $since, 'end_time' => $until],
            ],
            'pixel fields' => [
                'path' => $pixelId,
                'query' => ['fields' => 'id,name,last_fired_time,is_unavailable,data_use_setting'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function pixels(string $version, string $account, string $token): array
    {
        try {
            return Http::get("https://graph.facebook.com/{$version}/act_{$account}/adspixels", [
                'access_token' => $token,
                'fields' => 'id,name,last_fired_time',
                'limit' => 25,
            ])->throw()->json('data', []);
        } catch (Throwable $e) {
            $this->components->error('Could not list pixels: '.$e->getMessage());

            return [];
        }
    }

    /** @param  array{path: string, query: array<string, mixed>}  $request */
    private function attempt(string $version, string $token, string $label, array $request): void
    {
        try {
            $response = Http::timeout(20)->get(
                "https://graph.facebook.com/{$version}/{$request['path']}",
                $request['query'] + ['access_token' => $token],
            );
        } catch (Throwable $e) {
            $this->components->twoColumnDetail("  {$label}", '<fg=red>could not connect</>');

            return;
        }

        $body = $response->json() ?? [];

        if (isset($body['error'])) {
            // The message is the useful part: Meta says whether an edge is
            // gone, needs a permission, or was simply asked wrongly.
            $this->components->twoColumnDetail("  {$label}", '<fg=red>error</>');
            $this->line('    '.($body['error']['message'] ?? 'unknown'));

            return;
        }

        $rows = $body['data'] ?? [];

        $this->components->twoColumnDetail(
            "  {$label}",
            '<fg=green>ok</> · '.count($rows).' rows',
        );

        // One row of shape, so the fields can be read without guessing.
        if ($rows !== []) {
            $this->line('    '.mb_substr(json_encode($rows[0]), 0, 220));
        } elseif ($rows === [] && $body !== []) {
            $this->line('    '.mb_substr(json_encode($body), 0, 220));
        }
    }
}
