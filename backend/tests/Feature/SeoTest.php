<?php

namespace Tests\Feature;

use App\AI\Contracts\AiProvider;
use App\Integrations\IntegrationManager;
use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Search Console analysis. Each list must surface what an SEO consultant
 * would act on, and leave out what they would not: a search with three
 * impressions is not an opportunity, it is noise.
 */
class SeoTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Search Console data lags two days, so with "today" fixed at
        // 2026-10-30 the 28-day window is 2026-10-01 to 2026-10-28 and the
        // previous one 2026-09-03 to 2026-09-30.
        Carbon::setTestNow('2026-10-30 12:00:00');

        $this->project = Project::factory()->create(['name' => 'Totally Football']);
        Sanctum::actingAs($this->project->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function serviceAccountKey(): string
    {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($resource, $privateKey);

        return json_encode([
            'type' => 'service_account',
            'client_email' => 'launch-os@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]);
    }

    private function connect(): void
    {
        Integration::factory()->for($this->project)->create([
            'provider' => 'search_console',
            'credentials' => [
                'site_url' => 'sc-domain:totallyfootballgame.co.uk',
                'service_account_json' => $this->serviceAccountKey(),
            ],
        ]);
    }

    private static function row(array $keys, int $clicks, int $impressions, float $position): array
    {
        return [
            'keys' => $keys,
            'clicks' => $clicks,
            'impressions' => $impressions,
            'ctr' => $impressions > 0 ? $clicks / $impressions : 0,
            'position' => $position,
        ];
    }

    /** Answers each Search Console query by its dimensions and period. */
    private function fakeSearchConsole(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.token']),
            'www.googleapis.com/webmasters/*' => function (Request $request) {
                $current = $request['startDate'] === '2026-10-01';
                $dims = implode(',', $request['dimensions']);

                $rows = match ([$dims, $current]) {
                    ['query', true] => [
                        self::row(['football board game'], 2, 600, 8.4),
                        self::row(['best football board games'], 4, 46, 6.7),
                        self::row(['tactical football game'], 1, 30, 2.2),
                        self::row(['duel system'], 0, 19, 8.7),
                        self::row(['totally football'], 10, 25, 1.2),
                        self::row(['noise'], 0, 3, 9.0),
                    ],
                    ['query', false] => [
                        self::row(['football board game'], 1, 500, 11.0),
                        self::row(['tactical football game'], 1, 20, 1.5),
                        self::row(['old search'], 0, 15, 12.0),
                    ],
                    ['query,page', true] => [
                        self::row(['football board game', 'https://x.test/'], 2, 560, 8.2),
                        self::row(['football board game', 'https://x.test/blog/best/'], 0, 40, 25.0),
                        self::row(['football board games', 'https://x.test/'], 3, 180, 10.8),
                        self::row(['football board games', 'https://x.test/blog/best/'], 0, 60, 16.8),
                        self::row(['noise', 'https://x.test/'], 0, 3, 9.0),
                    ],
                    ['page', true] => [
                        self::row(['https://x.test/'], 60, 1400, 7.9),
                        self::row(['https://x.test/blog/best/'], 27, 600, 6.6),
                    ],
                    ['page', false] => [
                        self::row(['https://x.test/'], 40, 1000, 9.0),
                    ],
                    default => [],
                };

                return Http::response(['rows' => $rows]);
            },
        ]);
    }

    private function analysis(int $days = 28): array
    {
        return $this->getJson("/api/projects/{$this->project->id}/seo?days={$days}")->assertOk()->json('analysis');
    }

    public function test_it_says_when_search_console_is_not_connected(): void
    {
        $this->getJson("/api/projects/{$this->project->id}/seo")
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('analysis', null);
    }

    public function test_connecting_rejects_a_property_that_is_not_a_search_console_name(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/integrations/search_console/connect", [
            'credentials' => ['site_url' => 'totallyfootballgame.co.uk', 'service_account_json' => $this->serviceAccountKey()],
        ])->assertUnprocessable();
    }

    public function test_totals_compare_with_the_previous_period(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $summary = $this->analysis()['summary'];

        $this->assertSame(87, $summary['current']['clicks']);
        $this->assertSame(2000, $summary['current']['impressions']);
        $this->assertSame(40, $summary['previous']['clicks']);
        // Impression-weighted: (7.9×1400 + 6.6×600) / 2000.
        $this->assertSame(7.5, $summary['current']['position']);
    }

    public function test_striking_distance_lists_page_two_searches_and_ignores_noise(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $striking = collect($this->analysis()['striking_distance']);

        $this->assertSame('football board game', $striking[0]['query']);
        $this->assertSame('https://x.test/', $striking[0]['page']);
        $this->assertContains('football board games', $striking->pluck('query'));
        // Position 25 is beyond striking distance; three impressions is noise.
        $this->assertFalse($striking->contains(fn ($r) => $r['position'] > 20));
        $this->assertNotContains('noise', $striking->pluck('query'));
    }

    public function test_low_ctr_flags_page_one_results_far_below_normal(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $low = collect($this->analysis()['low_ctr']);

        // 600 impressions at position 8 with 0.3% CTR, against about 2.5%.
        $this->assertSame('football board game', $low[0]['key']);
        $this->assertGreaterThan(10, $low[0]['missed_clicks']);
        // Branded search at position 1.2 with 40% CTR is doing fine.
        $this->assertNotContains('totally football', $low->pluck('key'));
    }

    public function test_cannibalization_finds_a_search_split_across_pages(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $split = collect($this->analysis()['cannibalization']);

        $this->assertSame(['football board games'], $split->pluck('query')->all());
        $this->assertCount(2, $split[0]['pages']);
    }

    public function test_movers_show_risers_new_and_lost_searches(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $movers = $this->analysis()['movers'];

        $this->assertSame('football board game', $movers['risers'][0]['key']);
        $this->assertSame(2.6, $movers['risers'][0]['change']);
        $this->assertContains('best football board games', collect($movers['new'])->pluck('key'));
        $this->assertSame('old search', $movers['lost'][0]['key']);
    }

    public function test_results_are_cached_until_refreshed(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $this->analysis();
        $this->analysis();
        Http::assertSentCount(6); // one token + five queries

        $this->getJson("/api/projects/{$this->project->id}/seo?days=28&refresh=1")->assertOk();
        Http::assertSentCount(11);
    }

    public function test_a_refused_key_explains_how_to_grant_access(): void
    {
        $this->connect();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/*' => Http::response(['error' => ['message' => 'denied']], 403),
        ]);

        $this->getJson("/api/projects/{$this->project->id}/seo")
            ->assertStatus(502)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Users and permissions'));
    }

    public function test_syncing_stores_daily_search_totals(): void
    {
        $this->connect();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'www.googleapis.com/webmasters/*' => Http::response(['rows' => [self::row(['2026-10-20'], 5, 200, 8.5)]]),
        ]);

        $this->assertTrue(app(IntegrationManager::class)->for($this->project, 'search_console')->sync()->ok);

        $this->assertDatabaseHas('metric_snapshots', ['project_id' => $this->project->id, 'source' => 'search_console', 'metric' => 'search_clicks', 'value' => 5]);
        $this->assertDatabaseHas('metric_snapshots', ['project_id' => $this->project->id, 'metric' => 'search_ctr', 'value' => 2.5]);
    }

    public function test_recommendations_are_cleaned_up_into_plan_ready_tasks(): void
    {
        $this->connect();
        $this->fakeSearchConsole();

        $this->app->instance(AiProvider::class, new class implements AiProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(string $system, string $prompt, int $maxTokens = 1024): ?string
            {
                return "Here you go:\n```json\n".json_encode([
                    ['title' => 'Retitle the homepage', 'why' => '600 impressions at 8.4', 'action' => 'Lead with "football board game"', 'priority' => 'HIGH', 'category' => 'website', 'content_type' => null],
                    ['title' => 'Write a games like Subbuteo post', 'why' => 'demand', 'action' => 'Write it', 'priority' => 'medium', 'category' => 'nonsense', 'content_type' => 'blog_post'],
                    ['title' => '', 'action' => 'dropped: no title'],
                ])."\n```";
            }
        });

        $recommendations = $this->postJson("/api/projects/{$this->project->id}/seo/recommendations", ['days' => 28])
            ->assertOk()
            ->json('recommendations');

        $this->assertCount(2, $recommendations);
        $this->assertSame('high', $recommendations[0]['priority']);
        $this->assertSame('website', $recommendations[1]['category']);
        $this->assertSame('blog_post', $recommendations[1]['content_type']);
    }

    public function test_recommendations_say_plainly_when_ai_is_not_set_up(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiProvider::class);
        $this->connect();

        $this->postJson("/api/projects/{$this->project->id}/seo/recommendations")
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'ANTHROPIC_API_KEY'));
    }

    public function test_strangers_cannot_read_a_projects_search_data(): void
    {
        $this->connect();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$this->project->id}/seo")->assertForbidden();
        $this->postJson("/api/projects/{$this->project->id}/seo/recommendations")->assertForbidden();
    }
}
