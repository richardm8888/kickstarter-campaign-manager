<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LandingPageAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private function goodPage(): string
    {
        return <<<'HTML'
        <html><head>
            <meta name="viewport" content="width=device-width">
            <meta name="description" content="A tactical football board game">
            <script>gtag('config','G-XYZ');</script>
        </head><body>
            <h1>Totally Football</h1>
            <video src="/promo.mp4"></video>
            <form><input type="email" name="email"><button>Notify me</button></form>
            <section>What our testimonial backers say</section>
            <section>FAQ: frequently asked questions</section>
            <p>Launching soon on Kickstarter</p>
        </body></html>
        HTML;
    }

    public function test_it_scores_a_well_built_page_highly(): void
    {
        Http::fake(['https://example.com*' => Http::response($this->goodPage())]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com/launch',
        ])->assertCreated();

        $this->assertGreaterThanOrEqual(90, $response->json('analysis.score'));

        $checks = collect($response->json('analysis.checks'))->keyBy('key');
        $this->assertTrue($checks['email_capture']['passed']);
        $this->assertTrue($checks['video']['passed']);
        $this->assertTrue($checks['tracking']['passed']);
        $this->assertTrue($checks['https']['passed']);
    }

    public function test_it_identifies_what_a_bare_page_is_missing(): void
    {
        Http::fake(['https://example.com*' => Http::response('<html><body><p>Coming soon</p></body></html>')]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com',
        ])->assertCreated();

        $this->assertLessThan(50, $response->json('analysis.score'));

        $checks = collect($response->json('analysis.checks'))->keyBy('key');
        $this->assertFalse($checks['email_capture']['passed']);
        $this->assertFalse($checks['headline']['passed']);
        $this->assertNotEmpty($checks['email_capture']['recommendation']);

        // The summary names the next action, not the label of what failed:
        // "biggest wins: captures email addresses" reads as though it does.
        $this->assertStringContainsString(
            'Start here: Add an email capture form',
            $response->json('analysis.summary'),
        );
    }

    public function test_analyses_are_kept_as_history(): void
    {
        Http::fake(['https://example.com*' => Http::response($this->goodPage())]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com']);
        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com']);

        $this->getJson("/api/projects/{$project->id}/page-analyses")
            ->assertOk()
            ->assertJsonCount(2, 'analyses');
    }

    public function test_it_can_remember_the_url_as_the_projects_landing_page(): void
    {
        Http::fake(['https://example.com*' => Http::response($this->goodPage())]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com/launch',
            'remember' => true,
        ])->assertCreated();

        $this->assertSame('https://example.com/launch', $project->fresh()->external_landing_url);
    }

    public function test_campaign_health_scores_the_external_page_when_one_is_used(): void
    {
        Http::fake(['https://example.com*' => Http::response($this->goodPage())]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com',
            'remember' => true,
        ]);

        $factors = collect($this->getJson("/api/projects/{$project->id}/health")->json('factors'))
            ->keyBy('key');

        $this->assertGreaterThanOrEqual(90, $factors['landing_page']['score']);
    }

    public function test_unreachable_pages_produce_a_helpful_validation_error(): void
    {
        Http::fake(['https://example.com*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out')]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_a_page_that_blocks_us_is_not_scored_as_a_bad_page(): void
    {
        // Kickstarter and friends return 403 to automated readers. Every
        // content check fails when there is no content, so storing that
        // run would tell the creator their page scores 8 out of 100.
        Http::fake(['https://example.com*' => Http::response('Forbidden', 403)]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertSame(0, $project->landingPageAnalyses()->count());
    }

    public function test_pages_are_fetched_as_a_browser_would(): void
    {
        // Announcing ourselves as a tool is the better instinct and it is
        // what we did first, but bot protection 403s it, and we cannot
        // read a creator's own public page without getting through.
        Http::fake(['https://example.com*' => Http::response($this->goodPage())]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com'])
            ->assertCreated();

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0], 'Chrome/')
            && $request->hasHeader('Accept-Language'));
    }

    public function test_other_users_cannot_analyse_a_project_they_do_not_own(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->postJson("/api/projects/{$project->id}/page-analyses", ['url' => 'https://example.com'])
            ->assertForbidden();
    }

    public function test_analyses_saved_before_three_state_results_are_read_forward(): void
    {
        // Rows written by the two-state version are still in every existing
        // project's history. Serving them without a `result` crashed the
        // page that renders them, so the shape is completed on read.
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        \DB::table('landing_page_analyses')->insert([
            'project_id' => $project->id,
            'url' => 'https://example.com',
            'page_type' => 'landing',
            'score' => 60,
            'checks' => json_encode([
                ['key' => 'email_capture', 'label' => 'Captures email addresses', 'passed' => true, 'weight' => 25, 'recommendation' => ''],
                ['key' => 'video', 'label' => 'Has a video', 'passed' => false, 'weight' => 15, 'recommendation' => 'Add one'],
            ]),
            'summary' => 'Old analysis',
            'created_at' => now(),
        ]);

        $checks = collect($this->getJson("/api/projects/{$project->id}/page-analyses")->assertOk()
            ->json('analyses.0.checks'))->keyBy('key');

        $this->assertSame('pass', $checks['email_capture']['result']);
        $this->assertSame('fail', $checks['video']['result']);
        $this->assertNull($checks['video']['detail']);
    }

    public function test_a_javascript_loaded_form_counts_as_email_capture(): void
    {
        // MailerLite (and most providers) inject the form at runtime, so the
        // fetched markup contains only the embed script.
        Http::fake(['https://example.com*' => Http::response(
            '<html><body><h1>Totally Football</h1>'
            .'<div class="ml-embedded" data-form="abc"></div>'
            .'<script src="https://assets.mailerlite.com/js/universal.js"></script>'
            .'</body></html>'
        )]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com',
        ])->assertCreated();

        $checks = collect($response->json('analysis.checks'))->keyBy('key');

        $this->assertTrue($checks['email_capture']['passed']);
    }
}
