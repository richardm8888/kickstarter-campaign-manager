<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdStatusAndDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function ad(Project $project, array $dimensions, array $totals, int $daysAgo = 1): void
    {
        $recorder = app(MetricRecorder::class);

        foreach ($totals as $metric => $value) {
            $recorder->record($project, 'meta', $metric, $value, now()->subDays($daysAgo), $dimensions + [
                'ad_name' => $dimensions['ad_id'],
            ]);
        }
    }

    private function connectedProject(): Project
    {
        $project = Project::factory()->create();
        $project->integrations()->create([
            'provider' => 'meta',
            'status' => 'connected',
            'credentials' => ['access_token' => 'x', 'ad_account_id' => '1'],
        ]);

        return $project;
    }

    public function test_a_turned_off_ad_gets_no_verdict_to_act_on(): void
    {
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        // Terrible numbers, which would be a loud "drop" were it running.
        $this->ad($project, ['ad_id' => 'paused', 'ad_type' => 'landing_page', 'ad_status' => 'PAUSED'], [
            'ad_spend' => 80.0, 'ad_clicks' => 400, 'ad_impressions' => 40000, 'ad_leads' => 0,
        ]);

        $ads = collect($this->getJson("/api/projects/{$project->id}/ads")->assertOk()->json('ads'))
            ->keyBy('ad_id');

        $this->assertFalse($ads['paused']['active']);
        $this->assertSame('off', $ads['paused']['verdict']);
        $this->assertStringContainsString('Not running', $ads['paused']['reason']);
    }

    public function test_an_ad_paused_by_its_campaign_counts_as_off(): void
    {
        // effective_status, not status: an ad set to ACTIVE inside a paused
        // campaign is not being shown to anyone.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'a', 'ad_type' => 'landing_page', 'ad_status' => 'CAMPAIGN_PAUSED'], [
            'ad_spend' => 50.0, 'ad_clicks' => 200, 'ad_impressions' => 20000, 'ad_leads' => 0,
        ]);

        $ads = collect($this->getJson("/api/projects/{$project->id}/ads")->json('ads'))->keyBy('ad_id');

        $this->assertFalse($ads['a']['active']);
    }

    public function test_an_ad_with_no_status_recorded_is_still_judged(): void
    {
        // Rows synced before status was fetched, and any field Meta stops
        // returning. Hiding those would make ads vanish silently.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'legacy', 'ad_type' => 'landing_page'], [
            'ad_spend' => 50.0, 'ad_clicks' => 200, 'ad_impressions' => 20000, 'ad_leads' => 0,
        ]);

        $ads = collect($this->getJson("/api/projects/{$project->id}/ads")->json('ads'))->keyBy('ad_id');

        $this->assertTrue($ads['legacy']['active']);
        $this->assertNotSame('off', $ads['legacy']['verdict']);
    }

    public function test_instant_form_clicks_do_not_look_like_a_broken_landing_page(): void
    {
        // The reported bug, with the reported numbers: 832 clicks against
        // 196 page views read as "76% of clicks never load your page",
        // when most of those clicks were on an instant form ad that was
        // never going to load a page at all.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'form', 'ad_type' => 'instant_form', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 636, 'ad_landing_page_views' => 0, 'ad_view_content' => 0,
        ]);

        $this->ad($project, ['ad_id' => 'page', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 196, 'ad_landing_page_views' => 196, 'ad_view_content' => 190,
        ]);

        $titles = collect($this->getJson("/api/projects/{$project->id}/ads/events")->assertOk()
            ->json('diagnostics'))->pluck('title');

        $this->assertEmpty($titles->filter(fn ($t) => str_contains($t, 'never load')));
        $this->assertEmpty($titles->filter(fn ($t) => str_contains($t, 'ViewContent is firing')));
    }

    public function test_kickstarter_page_views_do_not_look_like_a_missing_pixel(): void
    {
        // Meta counts a Kickstarter page load as a landing page view, but
        // our pixel cannot be on kickstarter.com, so ViewContent will
        // never match it however well the tag is installed.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'ks', 'ad_type' => 'kickstarter', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 300, 'ad_landing_page_views' => 250, 'ad_view_content' => 0,
        ]);

        $this->ad($project, ['ad_id' => 'own', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 100, 'ad_landing_page_views' => 90, 'ad_view_content' => 88,
        ]);

        $titles = collect($this->getJson("/api/projects/{$project->id}/ads/events")->json('diagnostics'))
            ->pluck('title');

        $this->assertEmpty($titles->filter(fn ($t) => str_contains($t, 'ViewContent is firing')));
    }

    public function test_a_genuinely_broken_pixel_is_still_reported(): void
    {
        // The diagnostics have to keep working, or narrowing them has just
        // turned a false alarm into a blind spot.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'own', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 400, 'ad_landing_page_views' => 300, 'ad_view_content' => 20,
        ]);

        $titles = collect($this->getJson("/api/projects/{$project->id}/ads/events")->json('diagnostics'))
            ->pluck('title');

        $this->assertNotEmpty($titles->filter(fn ($t) => str_contains($t, 'ViewContent is firing')));
    }

    public function test_a_genuinely_slow_page_is_still_reported(): void
    {
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'own', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_clicks' => 400, 'ad_landing_page_views' => 120, 'ad_view_content' => 118,
        ]);

        $titles = collect($this->getJson("/api/projects/{$project->id}/ads/events")->json('diagnostics'))
            ->pluck('title');

        $this->assertNotEmpty($titles->filter(fn ($t) => str_contains($t, 'never load')));
    }

    public function test_disabled_ads_are_not_counted_in_the_traffic_objective_warning(): void
    {
        // The warning tells you to go and rebuild a campaign. There is
        // nothing to rebuild on an ad that stopped running weeks ago, and
        // counting it made two live ads read as four.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        foreach (['old_a', 'old_b'] as $id) {
            $this->ad($project, [
                'ad_id' => $id, 'ad_type' => 'landing_page', 'ad_status' => 'PAUSED',
                'optimization_goal' => 'LANDING_PAGE_VIEWS',
            ], ['ad_spend' => 8.0, 'ad_clicks' => 40, 'ad_impressions' => 4000]);
        }

        $this->ad($project, [
            'ad_id' => 'live', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE',
            'optimization_goal' => 'LANDING_PAGE_VIEWS',
        ], ['ad_spend' => 5.0, 'ad_clicks' => 30, 'ad_impressions' => 3000]);

        $report = $this->getJson("/api/projects/{$project->id}/ads")->assertOk()->json();

        $this->assertSame(1, $report['traffic_objective_count']);
        $this->assertEquals(5.0, $report['traffic_objective_spend']);
    }

    public function test_wasted_spend_is_raised_at_realistic_pre_launch_budgets(): void
    {
        // Spend arrives from Meta in currency units. A threshold written
        // as 20_00 meant £2,000, which no pre-launch creator reaches, so
        // this signal silently never fired — indistinguishable from a
        // campaign with nothing wrong.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        // One ad producing leads, so the account has a cost per lead to
        // judge against — with none at all, nothing can be called wasteful.
        $this->ad($project, ['ad_id' => 'good', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_spend' => 10.0, 'ad_clicks' => 60, 'ad_impressions' => 6000, 'ad_leads' => 12,
        ]);

        // And one burning £45 at £22.50 a lead against a £0.90 ceiling.
        $this->ad($project, ['ad_id' => 'bad', 'ad_type' => 'landing_page', 'ad_status' => 'ACTIVE'], [
            'ad_spend' => 45.0, 'ad_clicks' => 300, 'ad_impressions' => 30000, 'ad_leads' => 2,
        ]);

        $tasks = collect($this->getJson("/api/projects/{$project->id}/daily")->json('tasks'));

        $wasted = $tasks->firstWhere('signal_key', 'ads_wasted_spend');

        $this->assertNotNull($wasted, 'a £45 ad going nowhere is worth a morning');
        $this->assertStringContainsString('£45.00', $wasted['why']);
    }

    public function test_the_report_says_when_meta_was_last_read(): void
    {
        // Without it there is no telling a stale figure from a wrong one.
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $project->integrations()->where('provider', 'meta')
            ->update(['last_synced_at' => now()->subMinutes(20)]);

        $this->assertNotNull(
            $this->getJson("/api/projects/{$project->id}/ads")->json('last_synced_at'),
        );
    }

    public function test_the_daily_list_does_not_raise_work_for_disabled_ads(): void
    {
        $project = $this->connectedProject();
        Sanctum::actingAs($project->user);

        $this->ad($project, ['ad_id' => 'paused', 'ad_type' => 'landing_page', 'ad_status' => 'PAUSED'], [
            'ad_spend' => 200.0, 'ad_clicks' => 900, 'ad_impressions' => 90000, 'ad_leads' => 0,
        ]);

        $keys = collect($this->getJson("/api/projects/{$project->id}/daily")->json('tasks'))
            ->pluck('signal_key');

        $this->assertNotContains('ads_wasted_spend', $keys);
    }
}
