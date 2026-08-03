<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Subscriber;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AudienceAndForecastTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $metric, float $value, array $dimensions = []): void
    {
        app(MetricRecorder::class)->record(
            $project, 'meta', $metric, $value, now()->subDay(), $dimensions ?: null,
        );
    }

    public function test_the_dashboard_counts_the_whole_list_not_just_imported_contacts(): void
    {
        $project = Project::factory()->create();

        // Six imported from Meta, but the provider knows about 67.
        Subscriber::factory()->for($project)->count(6)->create();
        app(MetricRecorder::class)->record($project, 'mailerlite', 'email_subscribers', 67);

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.email_subscribers.value', 67)
            ->assertJsonPath('funnel.2.value', 67);
    }

    public function test_the_local_count_wins_when_it_is_larger(): void
    {
        $project = Project::factory()->create();

        Subscriber::factory()->for($project)->count(20)->create();
        app(MetricRecorder::class)->record($project, 'mailerlite', 'email_subscribers', 5);

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.email_subscribers.value', 20);
    }

    public function test_an_ad_driving_kickstarter_follows_is_judged_on_cost_per_follow(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $dimensions = ['ad_id' => 'ks', 'ad_name' => 'Kickstarter Preview'];

        foreach ([
            'ad_spend' => 30.0,
            'ad_clicks' => 60,
            'ad_leads' => 0,
            'ad_follows' => 14,
            'ad_impressions' => 2000,
            'ad_view_content' => 40,
            'ad_landing_page_views' => 50,
        ] as $metric => $value) {
            $this->record($project, $metric, $value, $dimensions);
        }

        $ad = collect(app(AdPerformanceAnalyser::class)->analyse($project)['ads'])->firstWhere('ad_id', 'ks');

        $this->assertSame(14, $ad['follows']);
        $this->assertSame(2.14, $ad['cost_per_follow']);
        $this->assertSame('scale', $ad['verdict'], 'follows must not be treated as zero signups');
        $this->assertStringContainsString('Kickstarter follows', $ad['reason']);
    }

    public function test_an_expensive_follow_is_still_dropped(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $dimensions = ['ad_id' => 'ks', 'ad_name' => 'Kickstarter Preview'];

        foreach (['ad_spend' => 200.0, 'ad_clicks' => 60, 'ad_leads' => 0, 'ad_follows' => 5] as $metric => $value) {
            $this->record($project, $metric, $value, $dimensions);
        }

        $ad = collect(app(AdPerformanceAnalyser::class)->analyse($project)['ads'])->firstWhere('ad_id', 'ks');

        $this->assertSame('drop', $ad['verdict']);
    }

    public function test_saved_forecast_assumptions_are_used_next_time(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);
        Sanctum::actingAs($project->user);

        $this->putJson("/api/projects/{$project->id}/forecast/assumptions", [
            'planned_ad_spend' => 250000,
        ])->assertOk();

        $response = $this->getJson("/api/projects/{$project->id}/forecast")->assertOk();

        $this->assertSame(250000, $response->json('measured.planned_ad_spend'));
    }

    public function test_assumptions_are_scoped_to_the_owner(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->putJson("/api/projects/{$project->id}/forecast/assumptions", ['planned_ad_spend' => 1000])
            ->assertForbidden();
    }

    public function test_it_projects_across_every_backer_rate(): void
    {
        // 1,000 subscribers, £50 pledge, no ad spend planned.
        $project = Project::factory()->create(['average_pledge' => 5000, 'funding_goal' => 1000000]);
        Subscriber::factory()->for($project)->count(1000)->create();

        Sanctum::actingAs($project->user);

        $scenarios = collect(
            $this->getJson("/api/projects/{$project->id}/forecast?planned_ad_spend=0")->json('scenarios'),
        )->keyBy('label');

        $this->assertSame(['10%', '15%', '20%', '30%'], $scenarios->keys()->all());

        // 10% of 1,000 subscribers = 100 backers x £50 = £5,000.
        $this->assertSame(100, $scenarios['10%']['expected_backers']);
        $this->assertSame(500000, $scenarios['10%']['expected_funding']);
        $this->assertFalse($scenarios['10%']['funds_the_goal']);

        // 20% clears the £10,000 goal.
        $this->assertSame(200, $scenarios['20%']['expected_backers']);
        $this->assertTrue($scenarios['20%']['funds_the_goal']);
    }

    public function test_it_recommends_a_budget_from_measured_cost_and_conversion(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000, 'funding_goal' => 1000000]);

        // Measured: £1.00 per click, 20% of clicks become subscribers.
        $this->record($project, 'cpc', 1.0);
        $this->record($project, 'ad_clicks', 1000, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 200, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/forecast")->assertOk();

        // £10,000 goal / £50 = 200 backers; at 15% that needs 1,334
        // subscribers; at 20% conversion that is 6,670 clicks at £1 each.
        $this->assertSame(667000, $response->json('recommended_budget'));
        $this->assertTrue($response->json('measured.cpc_measured'));
        $this->assertTrue($response->json('measured.conversion_measured'));
    }

    public function test_no_budget_is_recommended_when_the_list_is_already_big_enough(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000, 'funding_goal' => 100000]);
        Subscriber::factory()->for($project)->count(500)->create();

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/forecast")
            ->assertOk()
            ->assertJsonPath('recommended_budget', 0);
    }

    public function test_rates_cannot_be_supplied_by_the_creator(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000]);
        Sanctum::actingAs($project->user);

        // Ignored rather than honoured: these are measured, not declared.
        $this->putJson("/api/projects/{$project->id}/forecast/assumptions", [
            'planned_ad_spend' => 100000,
            'cpc' => 99.0,
            'subscriber_to_backer_rate' => 0.99,
        ])->assertOk();

        $this->assertSame(
            ['planned_ad_spend' => 100000],
            $project->fresh()->forecast_assumptions,
        );
    }
}
