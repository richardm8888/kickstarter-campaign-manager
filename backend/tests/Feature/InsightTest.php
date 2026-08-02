<?php

namespace Tests\Feature;

use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InsightTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_detects_a_cpc_increase(): void
    {
        $project = Project::factory()->create();

        // Last week CPC ~£1.00, this week ~£1.30: a 30% increase.
        foreach (range(8, 13) as $daysAgo) {
            MetricSnapshot::factory()->for($project)
                ->metric('meta', 'cpc', 1.00, now()->subDays($daysAgo))->create();
        }
        foreach (range(1, 6) as $daysAgo) {
            MetricSnapshot::factory()->for($project)
                ->metric('meta', 'cpc', 1.30, now()->subDays($daysAgo))->create();
        }

        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/insights/generate")->assertCreated();

        $this->assertDatabaseHas('insights', [
            'project_id' => $project->id,
            'severity' => 'warning',
            'metadata->signal' => 'cpc_change',
        ]);
    }

    public function test_generation_reports_subscribers_needed_for_the_goal(): void
    {
        $project = Project::factory()->create(['funding_goal' => 10000_00, 'average_pledge' => 50_00]);
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/insights/generate")->assertCreated();

        $this->assertDatabaseHas('insights', ['metadata->signal' => 'subscribers_needed']);
    }

    public function test_signals_are_not_duplicated_on_the_same_day(): void
    {
        $project = Project::factory()->create(['funding_goal' => 10000_00, 'average_pledge' => 50_00]);
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/insights/generate");
        $this->postJson("/api/projects/{$project->id}/insights/generate");

        $this->assertSame(1, $project->insights()->where('metadata->signal', 'subscribers_needed')->count());
    }

    public function test_insights_can_be_acknowledged(): void
    {
        $project = Project::factory()->create();
        $insight = \App\Models\Insight::factory()->for($project)->create();

        Sanctum::actingAs($project->user);

        $this->patchJson("/api/projects/{$project->id}/insights/{$insight->id}/acknowledge")
            ->assertOk();

        $this->assertNotNull($insight->fresh()->acknowledged_at);
    }

    public function test_campaign_health_returns_score_factors_and_readiness(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/health")
            ->assertOk()
            ->assertJsonStructure([
                'score',
                'factors' => [['key', 'label', 'score', 'weight', 'recommendation']],
                'readiness' => ['ready', 'score', 'blockers'],
            ]);
    }

    public function test_forecast_endpoint_returns_forecast_and_editable_input(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/forecast")
            ->assertOk()
            ->assertJsonStructure([
                'forecast' => ['projected_visitors', 'expected_backers', 'expected_funding', 'confidence'],
                'input' => ['cpc', 'average_pledge', 'funding_goal'],
            ]);

        $this->postJson("/api/projects/{$project->id}/forecast/preview", [
            'planned_ad_spend' => 2000_00,
            'cpc' => 0.5,
        ])->assertOk()->assertJsonPath('forecast.projected_visitors', 4000);
    }
}
