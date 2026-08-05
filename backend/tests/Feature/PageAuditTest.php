<?php

namespace Tests\Feature;

use App\AI\Contracts\AiProvider;
use App\Models\Project;
use App\Services\PageAudit\PageContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The checks that go beyond "does the markup contain a form" — the ones
 * about whether a stranger would actually use the page.
 */
class PageAuditTest extends TestCase
{
    use RefreshDatabase;

    private function analyse(string $html, array $payload = []): array
    {
        Http::fake(['https://example.com*' => Http::response($html)]);

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->postJson("/api/projects/{$project->id}/page-analyses", [
            'url' => 'https://example.com',
            ...$payload,
        ])->assertCreated();

        return [
            'score' => $response->json('analysis.score'),
            'findings' => $response->json('analysis.findings'),
            'checks' => collect($response->json('analysis.checks'))->keyBy('key')->all(),
        ];
    }

    public function test_competing_calls_to_action_are_flagged(): void
    {
        $page = <<<'HTML'
        <html><body><h1>Totally Football: the tactical card game</h1>
            <button>Sign up</button>
            <button>Buy now</button>
            <button>Follow us</button>
            <button>Download the rules</button>
            <button>Contact us</button>
        </body></html>
        HTML;

        ['checks' => $checks] = $this->analyse($page);

        $this->assertFalse($checks['single_decision']['passed']);
        $this->assertStringContainsString('5', $checks['single_decision']['detail']);
    }

    public function test_navigation_links_are_not_counted_as_calls_to_action(): void
    {
        // A header full of menu items must not read as ten competing asks.
        $page = <<<'HTML'
        <html><body>
            <nav><a href="/">Home</a><a href="/about">About</a><a href="/blog">Blog</a>
                 <a href="/press">Press</a><a href="/terms">Terms</a></nav>
            <h1>Totally Football: the tactical card game</h1>
            <button>Notify me on launch</button>
        </body></html>
        HTML;

        ['checks' => $checks] = $this->analyse($page);

        $this->assertTrue($checks['single_decision']['passed']);
    }

    public function test_a_long_form_is_flagged(): void
    {
        $page = <<<'HTML'
        <html><body><h1>Totally Football: the tactical card game</h1>
            <form>
                <input type="email" name="email">
                <input type="text" name="first_name">
                <input type="text" name="last_name">
                <input type="tel" name="phone">
                <select name="country"></select>
                <input type="hidden" name="utm">
                <input type="submit" value="Join">
            </form>
        </body></html>
        HTML;

        ['checks' => $checks] = $this->analyse($page);

        $this->assertFalse($checks['short_form']['passed']);
        // Hidden fields and the submit button are not asks of the visitor.
        $this->assertStringContainsString('5 fields', $checks['short_form']['detail']);
    }

    public function test_a_call_to_action_buried_below_the_story_is_flagged(): void
    {
        $story = '<p>'.str_repeat('The game began as a napkin sketch. ', 60).'</p>';

        ['checks' => $checks] = $this->analyse(
            '<html><body><h1>Totally Football: the tactical card game</h1>'
            .$story.'<button>Sign up</button></body></html>'
        );

        $this->assertFalse($checks['cta_in_sight']['passed']);
        $this->assertStringContainsString('before the first call to action', $checks['cta_in_sight']['detail']);
    }

    public function test_a_generic_headline_is_flagged(): void
    {
        ['checks' => $checks] = $this->analyse('<html><body><h1>Welcome</h1></body></html>');

        $this->assertFalse($checks['specific_headline']['passed']);
        $this->assertStringContainsString('Welcome', $checks['specific_headline']['detail']);
    }

    public function test_an_undeterminable_check_does_not_count_against_the_score(): void
    {
        // A JavaScript form leaves no fields to count. Scoring that as a
        // failure would punish the creator for our blind spot.
        $withJsForm = '<html><body><h1>Totally Football: the tactical card game</h1>'
            .'<script src="https://assets.mailerlite.com/js/universal.js"></script>'
            .'<button>Notify me on launch</button></body></html>';

        ['checks' => $checks] = $this->analyse($withJsForm);

        $this->assertSame('unknown', $checks['short_form']['result']);
        $this->assertFalse($checks['short_form']['passed']);
        $this->assertSame(0, $checks['short_form']['weight']);
        $this->assertNotEmpty($checks['short_form']['recommendation']);
    }

    public function test_the_ux_walk_records_findings_without_moving_the_score(): void
    {
        $this->fakeCritic([
            ['severity' => 'critical', 'title' => 'Nobody learns what the game is',
                'body' => 'The headline says "Welcome".', 'fix' => 'Say what it is in six words.'],
            ['severity' => 'made-up', 'title' => 'Second point', 'body' => 'b', 'fix' => 'f'],
        ]);

        $page = '<html><body><h1>Welcome</h1><button>Sign up</button></body></html>';

        ['findings' => $findings, 'score' => $withAi] = $this->analyse($page);

        $this->assertCount(2, $findings);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertStringContainsString('Welcome', $findings[0]['body']);
        // An unrecognised severity is normalised rather than trusted.
        $this->assertSame('idea', $findings[1]['severity']);

        $this->app->forgetInstance(AiProvider::class);
        ['score' => $withoutAi] = $this->analyse($page);

        $this->assertSame($withoutAi, $withAi, 'the AI must never move the score');
    }

    public function test_findings_are_empty_when_no_ai_key_is_configured(): void
    {
        config(['services.anthropic.key' => null]);

        ['findings' => $findings, 'score' => $score] = $this->analyse(
            '<html><body><h1>Totally Football: the tactical card game</h1></body></html>'
        );

        $this->assertSame([], $findings);
        $this->assertGreaterThan(0, $score, 'the deterministic checks still run');
    }

    public function test_a_reply_that_is_not_json_is_discarded(): void
    {
        $this->mock(AiProvider::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('complete')->andReturn('I am afraid I cannot help with that.');
        });

        ['findings' => $findings] = $this->analyse('<html><body><h1>Anything</h1></body></html>');

        $this->assertSame([], $findings);
    }

    public function test_navigation_and_script_text_is_excluded_from_the_word_count(): void
    {
        $content = PageContent::parse(
            '<html><body><script>var x = "'.str_repeat('noise ', 100).'";</script>'
            .'<p>Four real words here</p></body></html>'
        );

        $this->assertSame(4, $content->wordCount);
    }

    /** @param  list<array<string, string>>  $findings */
    private function fakeCritic(array $findings): void
    {
        $this->mock(AiProvider::class, function ($mock) use ($findings) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('complete')->andReturn(
                "Here is the review:\n```json\n".json_encode($findings)."\n```"
            );
        });
    }
}
