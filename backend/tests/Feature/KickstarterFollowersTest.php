<?php

namespace Tests\Feature;

use App\Jobs\SyncKickstarterFollowers;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Services\Kickstarter\KickstarterFollowers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KickstarterFollowersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.kickstarter.com/projects/me/my-game';

    private function service(): KickstarterFollowers
    {
        return app(KickstarterFollowers::class);
    }

    public static function markupProvider(): array
    {
        return [
            'embedded json' => ['{"project":{"follower_count":1234,"name":"x"}}', 1234],
            'followers key' => ['<script>window.state = {"followers":  "987"}</script>', 987],
            'data attribute' => ['<div data-followers-count="4,321"></div>', 4321],
            'visible text' => ['<p><span>2,048</span> followers</p>', 2048],
            'plain text' => ['<p>512 followers so far</p>', 512],
        ];
    }

    #[DataProvider('markupProvider')]
    public function test_it_reads_the_count_from_the_shapes_kickstarter_uses(string $html, int $expected): void
    {
        $this->assertSame($expected, $this->service()->extract($html));
    }

    public function test_unreadable_markup_returns_null_rather_than_a_guess(): void
    {
        $this->assertNull($this->service()->extract('<html><body>No numbers here.</body></html>'));
    }

    public function test_it_records_the_count_as_a_metric(): void
    {
        Http::fake([self::URL.'*' => Http::response('{"follower_count":742}')]);

        $project = Project::factory()->create(['kickstarter_url' => self::URL]);

        $this->assertSame(742, $this->service()->sync($project));
        $this->assertSame(742.0, MetricSnapshot::where('metric', 'ks_followers')->value('value'));
    }

    public function test_a_failed_fetch_leaves_the_last_known_count_alone(): void
    {
        $project = Project::factory()->create(['kickstarter_url' => self::URL]);

        Http::fake([
            self::URL.'*' => Http::sequence()
                ->push('{"follower_count":300}')
                // Then the page goes down, or its markup changes.
                ->pushStatus(503)
                ->pushStatus(503)
                ->pushStatus(503),
        ]);

        $this->assertSame(300, $this->service()->sync($project));
        $this->assertNull($this->service()->sync($project));

        // A stale figure beats an invented one, so nothing is overwritten.
        $this->assertSame(1, MetricSnapshot::where('metric', 'ks_followers')->count());
    }

    public function test_only_kickstarter_urls_are_fetched(): void
    {
        // The server fetches this on a schedule, so it must not be
        // pointable at an arbitrary host.
        Http::fake();

        $project = Project::factory()->create(['kickstarter_url' => 'https://example.com/not-kickstarter']);

        $this->assertNull($this->service()->sync($project));
        Http::assertNothingSent();
    }

    public function test_the_settings_form_rejects_a_non_kickstarter_url(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->putJson("/api/projects/{$project->id}", ['kickstarter_url' => 'https://example.com/x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('kickstarter_url');

        $this->putJson("/api/projects/{$project->id}", ['kickstarter_url' => self::URL])
            ->assertOk()
            ->assertJsonPath('project.kickstarter_url', self::URL);
    }

    public function test_the_scheduled_job_skips_projects_with_no_page_linked(): void
    {
        Http::fake([self::URL.'*' => Http::response('{"follower_count":55}')]);

        Project::factory()->create(['kickstarter_url' => null]);
        Project::factory()->create(['kickstarter_url' => self::URL]);

        app(SyncKickstarterFollowers::class)->handle($this->service());

        $this->assertSame(1, MetricSnapshot::where('metric', 'ks_followers')->count());
    }

    public function test_followers_feed_the_forecast_at_follower_rates(): void
    {
        Http::fake([self::URL.'*' => Http::response('{"follower_count":500}')]);

        $project = Project::factory()->create([
            'kickstarter_url' => self::URL,
            'average_pledge' => 5000,
            'funding_goal' => 1000000,
        ]);

        $this->service()->sync($project);

        Sanctum::actingAs($project->user);
        $response = $this->getJson("/api/projects/{$project->id}/forecast?planned_ad_spend=0")->assertOk();

        $this->assertSame(500, $response->json('measured.followers'));

        // 500 followers at the expected 20% is 100 backers — the same as
        // 5,000 plain email subscribers would deliver.
        $scenarios = collect($response->json('scenarios'))->keyBy('scenario');
        $this->assertSame(100, $scenarios['expected']['expected_backers']);
        $this->assertSame(100, $scenarios['expected']['backers_by_segment']['followers']);
    }
}
