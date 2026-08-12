<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KickstarterPageAuditTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.kickstarter.com/projects/me/totally-football';

    private function analyse(string $html, array $payload = []): array
    {
        Http::fake([self::URL.'*' => Http::response($html)]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => self::URL,
            'page_type' => 'kickstarter',
            ...$payload,
        ])->assertCreated();

        return [
            'project' => $project,
            'score' => $response->json('analysis.score'),
            'checks' => collect($response->json('analysis.checks'))->keyBy('key')->all(),
        ];
    }

    private function livePage(int $tiers = 4): string
    {
        $rewards = str_repeat('<button>Select this reward</button>', $tiers);
        $story = str_repeat('A tactical football card game for two players. ', 60);

        return <<<HTML
        <html><head><title>Totally Football — Kickstarter</title></head><body>
            <h1>Totally Football</h1>
            <iframe src="https://player.vimeo.com/video/1"></iframe>
            <p>1,240 backers pledged £48,000 of £30,000 · 12 days to go</p>
            <img src="a.jpg" alt="box"><img src="b.jpg" alt="cards"><img src="c.jpg" alt="board">
            <section>{$story}</section>
            {$rewards}
            <section>Risks and challenges: manufacturing is quoted and tooling is paid for.</section>
            <section>FAQ: frequently asked questions about shipping and delivery</section>
            <section>About the creator: first created two games.</section>
        </body></html>
        HTML;
    }

    public function test_it_only_accepts_kickstarter_urls(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com/not-kickstarter',
            'page_type' => 'kickstarter',
        ])->assertUnprocessable()->assertJsonValidationErrors('url');
    }

    public function test_a_strong_live_campaign_scores_well(): void
    {
        ['score' => $score, 'checks' => $checks] = $this->analyse($this->livePage());

        $this->assertGreaterThanOrEqual(90, $score);
        $this->assertTrue($checks['video']['passed']);
        $this->assertTrue($checks['reward_tiers']['passed']);
        $this->assertTrue($checks['risks']['passed']);
        $this->assertTrue($checks['shipping']['passed']);
    }

    public function test_a_missing_video_is_the_heaviest_failure(): void
    {
        $noVideo = str_replace('<iframe src="https://player.vimeo.com/video/1"></iframe>', '', $this->livePage());

        ['checks' => $checks] = $this->analyse($noVideo);

        $this->assertFalse($checks['video']['passed']);
        $this->assertSame(20, $checks['video']['weight']);
    }

    public function test_too_many_reward_tiers_are_flagged(): void
    {
        ['checks' => $checks] = $this->analyse($this->livePage(tiers: 14));

        $this->assertFalse($checks['reward_tiers']['passed']);
        $this->assertStringContainsString('14 tiers', $checks['reward_tiers']['detail']);
    }

    public function test_a_pre_launch_page_is_judged_on_what_it_can_have(): void
    {
        // No tiers, no story and no backer count yet — none of which is a
        // fault, so none of it may be scored as one.
        $preLaunch = <<<'HTML'
        <html><head><title>Totally Football — Kickstarter</title></head><body>
            <h1>Totally Football</h1>
            <iframe src="https://player.vimeo.com/video/1"></iframe>
            <img src="a.jpg" alt="box"><img src="b.jpg" alt="cards"><img src="c.jpg" alt="board">
            <p>A tactical football card game for two players, coming soon.</p>
            <button>Notify me on launch</button>
        </body></html>
        HTML;

        ['checks' => $checks] = $this->analyse($preLaunch);

        $this->assertSame('unknown', $checks['reward_tiers']['result']);
        $this->assertSame('unknown', $checks['story_depth']['result']);
        $this->assertTrue($checks['notify_cta']['passed']);
        $this->assertArrayNotHasKey('risks', $checks, 'live-only checks must not appear pre-launch');
    }

    public function test_an_overlong_title_is_flagged(): void
    {
        $long = 'Totally Football: The Ultimate Tactical Card Game Of Managerial Brilliance And Late Drama';

        ['checks' => $checks] = $this->analyse(
            str_replace('<h1>Totally Football</h1>', "<h1>{$long}</h1>", $this->livePage())
        );

        $this->assertFalse($checks['title']['passed']);
        $this->assertStringContainsString('characters', $checks['title']['detail']);
    }

    public function test_the_kickstarter_suffix_is_not_counted_in_the_title(): void
    {
        // Kickstarter appends its own name to the document title; charging
        // a creator for those characters would be our error, not theirs.
        $page = str_replace('<h1>Totally Football</h1>', '', $this->livePage());

        ['checks' => $checks] = $this->analyse($page);

        $this->assertTrue($checks['title']['passed']);
        $this->assertStringNotContainsString('Kickstarter', $checks['title']['detail']);
    }

    public function test_it_can_remember_the_url_as_the_projects_kickstarter_page(): void
    {
        ['project' => $project] = $this->analyse($this->livePage(), ['remember' => true]);

        $this->assertSame(self::URL, $project->fresh()->kickstarter_url);
    }

    public function test_the_two_page_types_keep_separate_histories(): void
    {
        Http::fake([
            self::URL.'*' => Http::response($this->livePage()),
            'https://example.com*' => Http::response('<html><body><h1>My game</h1></body></html>'),
        ]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => self::URL, 'page_type' => 'kickstarter',
        ])->assertCreated();

        $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com',
        ])->assertCreated();

        $this->getJson("/api/projects/{$project->id}/page-analyses?page_type=kickstarter")
            ->assertOk()
            ->assertJsonCount(1, 'analyses')
            ->assertJsonPath('analyses.0.page_type', 'kickstarter');

        $this->getJson("/api/projects/{$project->id}/page-analyses?page_type=landing")
            ->assertOk()
            ->assertJsonCount(1, 'analyses');
    }
}
