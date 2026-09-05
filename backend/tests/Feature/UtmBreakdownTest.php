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

    /**
     * The real shape, read off the live API rather than recalled.
     *
     *   GET /campaigns/{id}/links -> data[] of
     *     { id, url, label, safe_url, clicks_count, unique_clicks_count }
     *
     * A previous version looked under `stats` on the campaign for four
     * guessed spellings, all wrong, and shipped an empty table.
     */
    private function fakeCampaignWithLinks(array $links): void
    {
        Http::fake([
            'connect.mailerlite.com/api/campaigns/*/links' => Http::response(['data' => $links]),
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => [[
                'id' => 'c1',
                'name' => 'Kickstarter pre-campaign launch',
                'finished_at' => now()->subDays(3)->toDateTimeString(),
                'stats' => ['sent' => 60, 'opens_count' => 23, 'clicks_count' => 14],
            ]]]),
            'connect.mailerlite.com/api/automations*' => Http::response(['data' => []]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => [], 'total' => 60]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => []]),
        ]);
    }

    private function connectMailerLite(Project $project): void
    {
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => [],
        ]);
    }

    public function test_link_clicks_are_read_from_the_links_endpoint(): void
    {
        $project = Project::factory()->create();
        $this->connectMailerLite($project);

        $this->fakeCampaignWithLinks([
            [
                'id' => 'l1',
                'url' => 'https://www.kickstarter.com/projects/double-time-games/totally-football',
                'clicks_count' => 20,
                'unique_clicks_count' => 14,
            ],
            [
                'id' => 'l2',
                'url' => 'https://www.instagram.com/totallyfootballgame/',
                'clicks_count' => 0,
                'unique_clicks_count' => 0,
            ],
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $links = app(ConversionBreakdown::class)->build($project, 30)['email_links'];

        $this->assertCount(2, $links);
        $this->assertSame('https://www.kickstarter.com/projects/double-time-games/totally-football', $links[0]['url']);
        // Every click, not people: twenty clicks from fourteen people is
        // a different story from twenty people.
        $this->assertSame(20, $links[0]['clicks']);
        $this->assertSame('Kickstarter pre-campaign launch', $links[0]['campaign']);
    }

    public function test_mailerlites_own_placeholder_links_are_left_out(): void
    {
        $project = Project::factory()->create();
        $this->connectMailerLite($project);

        // Every email carries these, by template and by law. Reported,
        // they would sit at the top of the table forever saying nothing.
        $this->fakeCampaignWithLinks([
            ['id' => 'l1', 'url' => '{$unsubscribe}', 'clicks_count' => 3, 'unique_clicks_count' => 3],
            ['id' => 'l2', 'url' => '{$url}', 'clicks_count' => 2, 'unique_clicks_count' => 2],
            ['id' => 'l3', 'url' => 'https://totallyfootballgame.co.uk', 'clicks_count' => 9, 'unique_clicks_count' => 7],
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $links = app(ConversionBreakdown::class)->build($project, 30)['email_links'];

        $this->assertCount(1, $links);
        $this->assertSame('https://totallyfootballgame.co.uk', $links[0]['url']);
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
