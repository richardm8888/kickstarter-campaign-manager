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

        $funnel = collect($response->json('funnel'))->keyBy('key');

        $this->assertSame(800, $funnel['ads']['value']);
        $this->assertSame(500, $funnel['landing_page']['value']);
        $this->assertSame(50, $funnel['email_signup']['value']);
        $this->assertSame(10, $funnel['vip_upgrade']['value']);
        $this->assertSame(62.5, $funnel['landing_page']['conversion']);
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
