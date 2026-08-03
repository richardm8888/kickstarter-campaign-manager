<?php

namespace Tests\Feature;

use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_cards_and_funnel(): void
    {
        $project = Project::factory()->create();

        MetricSnapshot::factory()->for($project)
            ->metric('ga4', 'sessions', 500, now()->subDay())->create();
        MetricSnapshot::factory()->for($project)
            ->metric('meta', 'clicks', 800, now()->subDay())->create();
        MetricSnapshot::factory()->for($project)
            ->metric('meta', 'spend', 120, now()->subDay())->create();
        MetricSnapshot::factory()->for($project)
            ->metric('meta', 'impressions', 40000, now()->subDay())->create();

        Subscriber::factory()->for($project)->count(40)->create();
        Subscriber::factory()->for($project)->vip()->count(10)->create();

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/dashboard");

        $response->assertOk()
            ->assertJsonPath('cards.visitors.value', 500)
            ->assertJsonPath('cards.email_subscribers.value', 50)
            ->assertJsonPath('cards.vip_upgrades.value', 10)
            ->assertJsonPath('cards.conversion_rate.value', 10)
            ->assertJsonPath('cards.cac.value', 240) // £120 spend / 50 subscribers = £2.40, in pence
            ->assertJsonStructure(['cards' => ['funding_forecast' => ['value', 'goal', 'coverage', 'confidence']]]);

        $funnel = collect($response->json('funnel.steps'))->keyBy('key');

        $this->assertSame(40000, $funnel['impressions']['value']);
        $this->assertSame(12000, $funnel['impressions']['spend'], 'spend rides along in minor units');
        $this->assertSame(800, $funnel['ad_clicks']['value']);
        // JSON drops the fractional zero, so compare loosely.
        $this->assertEquals(2.0, $funnel['ad_clicks']['conversion'], 'clicks over impressions is the CTR');
        $this->assertSame(50, $funnel['signups']['value']);
        $this->assertSame(10, $funnel['vip_upgrade']['value']);

        // No per-ad landing page views, so GA4 sessions stand in.
        $branches = collect($funnel['reached']['branches'])->keyBy('key');
        $this->assertSame(500, $branches['landing_page_views']['value']);
        $this->assertSame(62.5, $branches['landing_page_views']['conversion']);
        $this->assertSame(0, $branches['form_views']['value']);
    }

    public function test_dashboard_handles_a_brand_new_project_with_no_data(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.visitors.value', 0)
            ->assertJsonPath('cards.conversion_rate.value', null)
            ->assertJsonPath('cards.cac.value', null);
    }
}
