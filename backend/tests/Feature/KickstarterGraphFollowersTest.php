<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Kickstarter\KickstarterFollowers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The count is never in the HTML — React fetches it after load — so we
 * ask the same endpoint the page asks, with the query taken from the
 * page's own PrelaunchPage operation.
 */
class KickstarterGraphFollowersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.kickstarter.com/projects/double-time-games/totally-football';

    private function shell(): string
    {
        // What the server really sends: a page with no count in it.
        return '<html><head><meta name="csrf-token" content="tok-123"></head>'
            .'<body><div id="react-root"></div></body></html>';
    }

    private function fakeGraph(array $response): void
    {
        Http::fake([
            'https://www.kickstarter.com/graph' => Http::response($response),
            'https://www.kickstarter.com/projects/*' => Http::response($this->shell()),
        ]);
    }

    public function test_it_reads_the_count_from_graphql(): void
    {
        $this->fakeGraph([['data' => ['project' => ['watchesCount' => 19, 'state' => 'PRELAUNCH']]]]);

        $this->assertSame(19, app(KickstarterFollowers::class)->fetch(self::URL));
    }

    public function test_it_sends_the_csrf_token_taken_from_the_page(): void
    {
        $this->fakeGraph([['data' => ['project' => ['watchesCount' => 19]]]]);

        app(KickstarterFollowers::class)->fetch(self::URL);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/graph')) {
                return true;
            }

            // Sent as a batch, as the page sends it, and identified as
            // the same-origin XHR it is imitating.
            $body = $request->data();

            return $request->header('x-csrf-token')[0] === 'tok-123'
                && $request->header('Sec-Fetch-Site')[0] === 'same-origin'
                && array_is_list($body)
                && $body[0]['variables']['slug'] === 'double-time-games/totally-football';
        });
    }

    public function test_an_unbatched_response_is_read_too(): void
    {
        // The endpoint answers a list with a list, but this costs nothing
        // and stops a shape change reading as zero followers.
        $this->fakeGraph(['data' => ['project' => ['watchesCount' => 42]]]);

        $this->assertSame(42, app(KickstarterFollowers::class)->fetch(self::URL));
    }

    public function test_graphql_errors_record_nothing_rather_than_guessing(): void
    {
        $this->fakeGraph([['errors' => [['message' => "Field 'watchesCount' doesn't exist"]]]]);

        $this->assertNull(app(KickstarterFollowers::class)->fetch(self::URL));
    }

    public function test_a_page_without_a_csrf_token_falls_back_to_the_markup(): void
    {
        Http::fake(['https://www.kickstarter.com/projects/*' => Http::response(
            '<html><body><p data-test-id="followers-count">19 followers</p></body></html>'
        )]);

        $this->assertSame(19, app(KickstarterFollowers::class)->fetch(self::URL));
    }

    public function test_the_count_reaches_the_dashboard(): void
    {
        $this->fakeGraph([['data' => ['project' => ['watchesCount' => 19]]]]);

        $project = Project::factory()->create(['kickstarter_url' => self::URL]);

        $this->assertSame(19, app(KickstarterFollowers::class)->sync($project));
        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'metric' => 'ks_followers',
            'value' => 19,
        ]);
    }
}
