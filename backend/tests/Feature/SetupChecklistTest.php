<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SetupChecklistTest extends TestCase
{
    use RefreshDatabase;

    private function checklist(Project $project): array
    {
        Sanctum::actingAs($project->user);

        $setup = $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->json('setup');

        return [
            'complete' => $setup['complete'],
            'steps' => collect($setup['steps'])->keyBy('key'),
        ];
    }

    public function test_a_new_project_has_every_step_open_in_journey_order(): void
    {
        ['complete' => $complete, 'steps' => $steps] = $this->checklist(
            Project::factory()->create(['launch_date' => null]),
        );

        $this->assertFalse($complete);
        $this->assertSame(
            ['launch_date', 'landing_page', 'kickstarter_url', 'meta', 'mailerlite', 'first_ad'],
            $steps->keys()->all(),
        );
        $this->assertFalse($steps->contains(fn (array $step) => $step['done']));
    }

    public function test_steps_tick_off_as_the_creator_sets_things_up(): void
    {
        $project = Project::factory()->create([
            'launch_date' => now()->addDays(60)->toDateString(),
            'kickstarter_url' => 'https://www.kickstarter.com/projects/me/game',
        ]);
        Integration::factory()->for($project)->create(['provider' => 'meta', 'status' => 'connected']);
        app(MetricRecorder::class)->record($project, 'meta', 'spend', 25.0);

        ['complete' => $complete, 'steps' => $steps] = $this->checklist($project);

        $this->assertFalse($complete);
        $this->assertTrue($steps['launch_date']['done']);
        $this->assertTrue($steps['kickstarter_url']['done']);
        $this->assertTrue($steps['meta']['done']);
        $this->assertTrue($steps['first_ad']['done']);
        $this->assertFalse($steps['mailerlite']['done']);
        $this->assertFalse($steps['landing_page']['done']);
    }

    public function test_an_external_landing_page_counts_as_having_a_page(): void
    {
        ['steps' => $steps] = $this->checklist(
            Project::factory()->create(['external_landing_url' => 'https://example.com']),
        );

        $this->assertTrue($steps['landing_page']['done']);
    }

    public function test_a_disconnected_integration_does_not_count(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'meta', 'status' => 'error']);

        ['steps' => $steps] = $this->checklist($project);

        $this->assertFalse($steps['meta']['done']);
    }

    public function test_the_dashboard_forecast_is_honest_about_a_brand_new_project(): void
    {
        $project = Project::factory()->create(['funding_goal' => 2500000]);
        Sanctum::actingAs($project->user);

        // No audience and no spend entered here must mean no backers — the
        // dashboard reports state, not a hypothetical default budget.
        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.projected_backers.value', 0)
            ->assertJsonPath('cards.funding_forecast.value', 0)
            ->assertJsonPath('cards.ks_followers.value', 0);
    }
}
