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
            ->assertJsonPath('funnel.steps.3.value', 67);
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

    public function test_confidence_reflects_sample_size_not_just_presence_of_data(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000]);

        // Real figures, but from far too little traffic to trust.
        $this->record($project, 'cpc', 0.04);
        $this->record($project, 'ad_clicks', 70, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 2, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/forecast")
            ->assertOk()
            ->assertJsonPath('confidence', 'low');
    }

    public function test_confidence_rises_once_there_is_enough_traffic(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000]);

        $this->record($project, 'cpc', 0.50);
        $this->record($project, 'ad_clicks', 900, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 120, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/forecast")
            ->assertOk()
            ->assertJsonPath('confidence', 'high');
    }

    public function test_a_poor_conversion_rate_is_flagged_as_the_thing_to_fix(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000]);

        // Cheap clicks, 2.9% of them subscribing.
        $this->record($project, 'cpc', 0.04);
        $this->record($project, 'ad_clicks', 1000, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 29, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/forecast")->assertOk();

        $this->assertSame('good', $response->json('ratings.cpc.rating'));
        $this->assertSame('danger', $response->json('ratings.conversion.rating'));

        $this->assertSame('critical', $response->json('focus.severity'));
        $this->assertStringContainsString('2.9%', $response->json('focus.title'));
        // Cheap traffic means the page, not the ads, is the opportunity.
        $this->assertStringContainsString('the page', $response->json('focus.body'));
    }

    public function test_expensive_clicks_are_flagged_when_the_page_converts_well(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000]);

        $this->record($project, 'cpc', 1.80);
        $this->record($project, 'ad_clicks', 1000, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 150, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/forecast")->assertOk();

        $this->assertSame('danger', $response->json('ratings.cpc.rating'));
        $this->assertSame('good', $response->json('ratings.conversion.rating'));
        $this->assertStringContainsString('Clicks cost', $response->json('focus.title'));
    }

    public function test_it_states_the_numbers_needed_and_how_likely_they_are(): void
    {
        // £10,000 goal, £50 pledge, no list, £1 clicks.
        $project = Project::factory()->create(['average_pledge' => 5000, 'funding_goal' => 1000000]);
        $this->record($project, 'cpc', 1.0);
        $this->record($project, 'ad_clicks', 1000, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 200, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/forecast?planned_ad_spend=100000")
            ->assertOk();

        // 200 backers needed; £1,000 buys 1,000 visitors.
        $this->assertSame(200, $response->json('requirements.backers_needed'));
        $this->assertSame(1000, $response->json('requirements.visitors_bought'));

        // At the 15% planning rate that needs 1,334 subscribers from 1,000
        // visitors — more than one each, so plainly unrealistic.
        $this->assertSame('unrealistic', $response->json('requirements.required_conversion.likelihood'));

        // Holding conversion at 20%, the 200-strong list would all have to back.
        $this->assertEquals(1.0, $response->json('requirements.required_backer_rate.rate'));
        $this->assertSame('unrealistic', $response->json('requirements.required_backer_rate.likelihood'));
    }

    public function test_a_comfortable_budget_reads_as_likely(): void
    {
        $project = Project::factory()->create(['average_pledge' => 5000, 'funding_goal' => 100000]);
        $this->record($project, 'cpc', 0.50);
        $this->record($project, 'ad_clicks', 1000, ['ad_id' => '1']);
        $this->record($project, 'ad_leads', 200, ['ad_id' => '1']);

        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/forecast?planned_ad_spend=500000")
            ->assertOk();

        $this->assertSame('likely', $response->json('requirements.required_backer_rate.likelihood'));
    }
}
