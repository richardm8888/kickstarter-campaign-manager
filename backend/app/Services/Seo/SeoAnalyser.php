<?php

namespace App\Services\Seo;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Reads a project's Search Console data the way an SEO consultant would.
 *
 * Every list here answers "what should we do": searches close to page one,
 * pages seen but not clicked, a search split across pages, and what moved
 * since the last period. All of it is arithmetic on Google's own numbers,
 * so it can be trusted without an AI in the loop; the advisor only turns
 * it into words.
 */
class SeoAnalyser
{
    public const WINDOWS = [28, 90];

    /** Re-asking Google on every page view gains nothing: its data moves daily. */
    private const CACHE_HOURS = 6;

    /**
     * Rough click-through rate by position on page one, from published
     * industry studies. Used only to spot results far below normal, so
     * the exact figures matter less than the shape.
     */
    private const BENCHMARK_CTR = [1 => 0.28, 2 => 0.15, 3 => 0.10, 4 => 0.07, 5 => 0.05, 6 => 0.04, 7 => 0.03, 8 => 0.025, 9 => 0.02, 10 => 0.018];

    public function __construct(private readonly SearchConsoleClient $client) {}

    public function connection(Project $project): ?Integration
    {
        return $project->integrations()
            ->where('provider', 'search_console')
            ->where('status', '!=', Integration::STATUS_DISCONNECTED)
            ->whereNotNull('credentials')
            ->first();
    }

    /** @return array<string, mixed>|null null when Search Console is not connected */
    public function analyse(Project $project, int $days = 90, bool $refresh = false): ?array
    {
        $integration = $this->connection($project);

        if ($integration === null) {
            return null;
        }

        $days = in_array($days, self::WINDOWS, true) ? $days : 90;
        $key = "seo-analysis:{$project->id}:{$days}:".sha1((string) $integration->updated_at);

        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addHours(self::CACHE_HOURS), fn () => $this->build($integration->credentials, $days));
    }

    /** @return array<string, mixed> */
    private function build(array $credentials, int $days): array
    {
        $end = Carbon::today()->subDays(SearchConsoleClient::LAG_DAYS);
        $start = $end->copy()->subDays($days - 1);
        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($days - 1);

        $fetch = fn (Carbon $from, Carbon $to, array $dimensions) => collect(
            $this->client->query($credentials, $from->toDateString(), $to->toDateString(), $dimensions)
        );

        $queries = $fetch($start, $end, ['query']);
        $previousQueries = $fetch($prevStart, $prevEnd, ['query']);
        $queryPages = $fetch($start, $end, ['query', 'page']);
        $pages = $fetch($start, $end, ['page']);
        $previousPages = $fetch($prevStart, $prevEnd, ['page']);

        return [
            'window' => [
                'days' => $days,
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'previous_start' => $prevStart->toDateString(),
                'previous_end' => $prevEnd->toDateString(),
            ],
            'summary' => [
                'current' => $this->totals($pages),
                'previous' => $this->totals($previousPages),
            ],
            'queries' => $this->withChange($queries, $previousQueries)->sortByDesc('impressions')->take(20)->values(),
            'pages' => $this->withChange($pages, $previousPages)->sortByDesc('impressions')->take(15)->values(),
            'striking_distance' => $this->strikingDistance($queryPages),
            'low_ctr' => $this->lowCtr($queries),
            'cannibalization' => $this->cannibalization($queryPages),
            'movers' => $this->movers($queries, $previousQueries),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /** Totals across pages: clicks and impressions add up; position is impression-weighted. */
    private function totals(Collection $rows): array
    {
        $clicks = $rows->sum('clicks');
        $impressions = $rows->sum('impressions');

        return [
            'clicks' => (int) $clicks,
            'impressions' => (int) $impressions,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : 0.0,
            'position' => $impressions > 0 ? round($rows->sum(fn ($r) => $r['position'] * $r['impressions']) / $impressions, 1) : null,
        ];
    }

    private function row(array $row): array
    {
        return [
            'key' => $row['keys'][0] ?? '',
            'clicks' => (int) $row['clicks'],
            'impressions' => (int) $row['impressions'],
            'ctr' => round($row['ctr'] * 100, 2),
            'position' => round($row['position'], 1),
        ];
    }

    private function withChange(Collection $current, Collection $previous): Collection
    {
        $before = $previous->keyBy(fn ($r) => $r['keys'][0] ?? '');

        return $current->map(function (array $row) use ($before) {
            $was = $before->get($row['keys'][0] ?? '');

            return [
                ...$this->row($row),
                'previous_clicks' => $was ? (int) $was['clicks'] : null,
                'previous_impressions' => $was ? (int) $was['impressions'] : null,
                'previous_position' => $was ? round($was['position'], 1) : null,
            ];
        });
    }

    /**
     * Searches at positions 6–20: already ranking, so better on-page focus
     * and a few links usually move them onto page one. The cheapest wins.
     */
    private function strikingDistance(Collection $queryPages): Collection
    {
        return $queryPages
            ->filter(fn ($r) => $r['position'] >= 6 && $r['position'] <= 20 && $r['impressions'] >= 5)
            ->sortByDesc('impressions')
            ->take(15)
            ->map(fn ($r) => [
                'query' => $r['keys'][0] ?? '',
                'page' => $r['keys'][1] ?? '',
                'impressions' => (int) $r['impressions'],
                'clicks' => (int) $r['clicks'],
                'ctr' => round($r['ctr'] * 100, 2),
                'position' => round($r['position'], 1),
            ])
            ->values();
    }

    /**
     * Page-one results clicked far less than results usually are at that
     * position: a title or description that is not earning the click.
     */
    private function lowCtr(Collection $queries): Collection
    {
        return $queries
            ->filter(fn ($r) => $r['position'] <= 10 && $r['impressions'] >= 20)
            ->map(function ($r) {
                $expected = self::BENCHMARK_CTR[max(1, min(10, (int) round($r['position'])))];

                return [
                    ...$this->row($r),
                    'expected_ctr' => round($expected * 100, 1),
                    'missed_clicks' => (int) round($r['impressions'] * ($expected - $r['ctr'])),
                    'below_half' => $r['ctr'] < $expected / 2,
                ];
            })
            ->filter(fn ($r) => $r['below_half'] && $r['missed_clicks'] >= 1)
            ->sortByDesc('missed_clicks')
            ->take(10)
            ->map(fn ($r) => collect($r)->except('below_half')->all())
            ->values();
    }

    /**
     * One search answered by several of the site's pages, each taking a
     * real share. Google is unsure which to rank, so neither ranks well.
     */
    private function cannibalization(Collection $queryPages): Collection
    {
        return $queryPages
            ->groupBy(fn ($r) => $r['keys'][0] ?? '')
            ->map(function (Collection $rows, string $query) {
                $total = $rows->sum('impressions');
                $competing = $rows->filter(fn ($r) => $total > 0 && $r['impressions'] / $total >= 0.15);

                return [
                    'query' => $query,
                    'impressions' => (int) $total,
                    'pages' => $competing->sortByDesc('impressions')->map(fn ($r) => [
                        'page' => $r['keys'][1] ?? '',
                        'impressions' => (int) $r['impressions'],
                        'position' => round($r['position'], 1),
                    ])->values()->all(),
                ];
            })
            ->filter(fn ($row) => count($row['pages']) >= 2 && $row['impressions'] >= 20)
            ->sortByDesc('impressions')
            ->take(10)
            ->values();
    }

    private function movers(Collection $current, Collection $previous): array
    {
        $now = $current->keyBy(fn ($r) => $r['keys'][0] ?? '');
        $before = $previous->keyBy(fn ($r) => $r['keys'][0] ?? '');

        $both = $now->filter(fn ($r, $q) => $before->has($q) && $r['impressions'] >= 10 && $before[$q]['impressions'] >= 5)
            ->map(fn ($r, $q) => [
                ...$this->row($r),
                'previous_position' => round($before[$q]['position'], 1),
                // Positive means it moved up the results.
                'change' => round($before[$q]['position'] - $r['position'], 1),
            ]);

        return [
            'risers' => $both->filter(fn ($r) => $r['change'] >= 1)->sortByDesc('change')->take(5)->values(),
            'fallers' => $both->filter(fn ($r) => $r['change'] <= -1)->sortBy('change')->take(5)->values(),
            'new' => $now->reject(fn ($r, $q) => $before->has($q))->filter(fn ($r) => $r['impressions'] >= 10)
                ->sortByDesc('impressions')->take(5)->map(fn ($r) => $this->row($r))->values(),
            'lost' => $before->reject(fn ($r, $q) => $now->has($q))->filter(fn ($r) => $r['impressions'] >= 10)
                ->sortByDesc('impressions')->take(5)->map(fn ($r) => $this->row($r))->values(),
        ];
    }
}
