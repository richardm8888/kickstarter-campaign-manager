<?php

namespace Tests\Feature;

use App\Integrations\Providers\MailerLiteIntegration;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\ConversionBreakdown;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Traffic split by utm_campaign and utm_medium, and the clicks that
 * happened inside the emails themselves.
 *
 * `sessionSource` alone puts every campaign in one row, so a list of five
 * emails reads as a single traffic source and there is no way to tell
 * which one worked. These are the cuts that separate them.
 */
class UtmBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $source, string $metric, float $value, array $dimensions): void
    {
        app(MetricRecorder::class)->record($project, $source, $metric, $value, now()->subDay(), $dimensions);
    }

    public function test_traffic_is_split_by_campaign(): void
    {
        $project = Project::factory()->create();

        foreach ([['spring-reveal', 120, 14], ['one-month-to-go', 40, 9]] as [$campaign, $sessions, $leads]) {
            $this->record($project, 'ga4', 'sessions_by_campaign', $sessions, ['campaign' => $campaign]);
            $this->record($project, 'ga4', 'leads_by_campaign', $leads, ['campaign' => $campaign]);
        }

        $rows = app(ConversionBreakdown::class)->build($project, 30)['by_campaign'];

        // Busiest first, so the one worth copying is at the top.
        $this->assertSame('spring-reveal', $rows[0]['key']);
        $this->assertSame(120, $rows[0]['sessions']);
        $this->assertSame(14, $rows[0]['leads']);
        $this->assertEqualsWithDelta(11.7, $rows[0]['conversion'], 0.1);
    }

    public function test_traffic_is_split_by_medium(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ga4', 'sessions_by_medium', 90, ['medium' => 'email']);
        $this->record($project, 'ga4', 'leads_by_medium', 12, ['medium' => 'email']);
        $this->record($project, 'ga4', 'sessions_by_medium', 300, ['medium' => 'cpc']);
        $this->record($project, 'ga4', 'leads_by_medium', 6, ['medium' => 'cpc']);

        $rows = collect(app(ConversionBreakdown::class)->build($project, 30)['by_medium'])->keyBy('key');

        // The whole point of the cut: email converts far better than paid,
        // and one blended rate would hide it.
        $this->assertGreaterThan($rows['cpc']['conversion'], $rows['email']['conversion']);
    }

    public function test_a_campaign_below_the_threshold_reports_no_rate(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ga4', 'sessions_by_campaign', 4, ['campaign' => 'tiny']);
        $this->record($project, 'ga4', 'leads_by_campaign', 1, ['campaign' => 'tiny']);

        $rows = app(ConversionBreakdown::class)->build($project, 30)['by_campaign'];

        $this->assertSame(4, $rows[0]['sessions']);
        $this->assertNull($rows[0]['conversion'], 'Four sessions cannot support a percentage.');
    }

    public function test_arrivals_at_the_kickstarter_page_are_split_by_campaign(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ga4', 'ks_page_sessions_by_campaign', 22, ['campaign' => 'one-month-to-go']);

        $rows = app(ConversionBreakdown::class)->build($project, 30)['kickstarter_arrivals_by_campaign'];

        $this->assertSame('one-month-to-go', $rows[0]['key']);
        $this->assertSame(22, $rows[0]['sessions']);
        // An arrival is still not a follow, whichever way it is cut.
        $this->assertNull($rows[0]['conversion']);
    }

    public function test_link_clicks_are_read_from_mailerlite(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => [],
        ]);

        Http::fake([
            'connect.mailerlite.com/api/campaigns/c1' => Http::response(['data' => [
                'id' => 'c1',
                'stats' => ['clicks_by_link' => [
                    ['url' => 'https://kickstarter.com/projects/x', 'count' => 41],
                    ['url' => 'https://totallyfootballgame.co.uk', 'count' => 12],
                ]],
            ]]),
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => [[
                'id' => 'c1',
                'name' => 'One month to go',
                'finished_at' => now()->subDays(3)->toDateTimeString(),
                'stats' => ['sent' => 260, 'opens_count' => 90, 'clicks_count' => 53],
            ]]]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => [], 'total' => 260]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => []]),
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $links = app(ConversionBreakdown::class)->build($project, 30)['email_links'];

        $this->assertCount(2, $links);
        $this->assertSame('https://kickstarter.com/projects/x', $links[0]['url']);
        $this->assertSame(41, $links[0]['clicks']);
        $this->assertSame('One month to go', $links[0]['campaign']);
    }

    public function test_an_unrecognised_link_shape_records_nothing_rather_than_guessing(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => [],
        ]);

        Http::fake([
            // A shape none of the candidate spellings match.
            'connect.mailerlite.com/api/campaigns/c1' => Http::response(['data' => [
                'id' => 'c1',
                'stats' => ['some_future_field' => [['href' => 'https://x', 'hits' => 9]]],
            ]]),
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => [[
                'id' => 'c1',
                'name' => 'One month to go',
                'finished_at' => now()->subDays(3)->toDateTimeString(),
                'stats' => ['sent' => 260],
            ]]]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => [], 'total' => 260]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => []]),
        ]);

        // The sync still succeeds — an unknown link shape is not a reason
        // to lose the subscriber count and the send with it.
        $result = app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $this->assertTrue($result->ok);
        $this->assertSame([], app(ConversionBreakdown::class)->build($project, 30)['email_links']);
        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'email_campaign_sent']);
    }

    public function test_the_new_cuts_reach_the_conversion_tab(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/analytics?category=conversion")
            ->assertOk()
            ->assertJsonStructure(['breakdown' => [
                'by_source', 'by_campaign', 'by_medium', 'by_region',
                'kickstarter_arrivals', 'kickstarter_arrivals_by_campaign', 'email_links',
            ]]);
    }
}
