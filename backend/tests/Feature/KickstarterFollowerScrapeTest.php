<?php

namespace Tests\Feature;

use App\Services\Kickstarter\KickstarterFollowers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Patterns pinned to markup taken from a real pre-launch page, so a
 * change to Kickstarter's rendering shows up here as a failing test
 * rather than as a follower count that quietly stops moving.
 */
class KickstarterFollowerScrapeTest extends TestCase
{
    use RefreshDatabase;

    private function extract(string $html): ?int
    {
        return app(KickstarterFollowers::class)->extract($html);
    }

    public function test_it_reads_the_count_a_prelaunch_page_renders(): void
    {
        // Verbatim from kickstarter.com/projects/double-time-games/…
        $html = '<p data-test-id="followers-count" '
            .'class="text-center kds-type kds-type-heading-md">19 followers</p>';

        $this->assertSame(19, $this->extract($html));
    }

    public function test_it_reads_a_count_with_thousands_separators(): void
    {
        $this->assertSame(1234, $this->extract(
            '<p data-test-id="followers-count">1,234 followers</p>'
        ));
    }

    public function test_an_abbreviated_count_is_expanded(): void
    {
        // "1.2K" meaning 1 would understate the best audience a campaign
        // has by three orders of magnitude.
        $this->assertSame(1200, $this->extract(
            '<p data-test-id="followers-count">1.2K followers</p>'
        ));
    }

    public function test_feature_flags_are_not_mistaken_for_a_count(): void
    {
        // The config blob Kickstarter ships at the top of every page. It
        // is full of numbered names and no follower count at all.
        $noise = '{"backer_report_update_2024":true,"self_serve_backer_removal":true,'
            .'"followers_only_updates":true,"backer_type_filters_2026":true}';

        $this->assertNull($this->extract($noise));
    }

    public function test_the_page_count_wins_over_surrounding_noise(): void
    {
        $html = '<script>{"backer_report_update_2024":true,"prelaunch_story_editor":true}</script>'
            .'<p data-test-id="followers-count">19 followers</p>';

        $this->assertSame(19, $this->extract($html));
    }

    public function test_a_page_without_a_count_records_nothing(): void
    {
        $this->assertNull($this->extract('<html><body><h1>Coming soon</h1></body></html>'));
    }

    public function test_the_sync_records_what_it_reads(): void
    {
        \Illuminate\Support\Facades\Http::fake(['https://www.kickstarter.com/*' => \Illuminate\Support\Facades\Http::response(
            '<p data-test-id="followers-count">19 followers</p>'
        )]);

        $project = \App\Models\Project::factory()->create([
            'kickstarter_url' => 'https://www.kickstarter.com/projects/double-time-games/totally-football',
        ]);

        $count = app(KickstarterFollowers::class)->sync($project);

        $this->assertSame(19, $count);
        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'metric' => 'ks_followers',
            'source' => 'kickstarter',
            'value' => 19,
        ]);
    }
}
