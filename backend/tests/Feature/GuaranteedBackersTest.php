<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Friends and family back regardless of anything the funnel does. They
 * are added on top rather than mixed into a segment, because no ad
 * bought them and no conversion rate applies to them.
 */
class GuaranteedBackersTest extends TestCase
{
    use RefreshDatabase;

    public function test_they_are_added_to_every_scenario(): void
    {
        $project = Project::factory()->create(['guaranteed_backers' => 0]);
        Sanctum::actingAs($project->user);

        $before = collect($this->getJson("/api/projects/{$project->id}/forecast")->json('scenarios'))
            ->pluck('expected_backers', 'scenario');

        $project->update(['guaranteed_backers' => 12]);

        $after = collect($this->getJson("/api/projects/{$project->id}/forecast")->json('scenarios'))
            ->pluck('expected_backers', 'scenario');

        // A flat addition, not a multiplier: the same twelve people
        // whether the campaign goes well or badly.
        foreach ($before as $scenario => $count) {
            $this->assertSame($count + 12, $after[$scenario], "scenario {$scenario}");
        }
    }

    public function test_they_appear_as_their_own_line_not_inside_a_segment(): void
    {
        $project = Project::factory()->create(['guaranteed_backers' => 12]);
        Sanctum::actingAs($project->user);

        $planning = collect($this->getJson("/api/projects/{$project->id}/forecast")->json('scenarios'))
            ->firstWhere('is_planning', true);

        $this->assertSame(12, $planning['backers_by_segment']['guaranteed']);
    }

    public function test_they_do_not_distort_the_conversion_rates(): void
    {
        // The audience value table is about what a segment converts at.
        // Twelve backers who never converted must not appear in it.
        $project = Project::factory()->create(['guaranteed_backers' => 12]);
        Sanctum::actingAs($project->user);

        $segments = collect($this->getJson("/api/projects/{$project->id}/forecast")->json('audience_value'))
            ->pluck('segment');

        $this->assertNotContains('guaranteed', $segments);
    }

    public function test_they_reduce_the_budget_needed_to_reach_the_goal(): void
    {
        $project = Project::factory()->create([
            'funding_goal' => 20_000_00,
            'average_pledge' => 45_00,
            'guaranteed_backers' => 0,
        ]);
        Sanctum::actingAs($project->user);

        $without = $this->getJson("/api/projects/{$project->id}/forecast")->json('recommended_budget');

        $project->update(['guaranteed_backers' => 50]);

        $with = $this->getJson("/api/projects/{$project->id}/forecast")->json('recommended_budget');

        $this->assertLessThan($without, $with, 'promised backers are backers you need not buy');
    }

    public function test_the_count_can_be_saved_and_is_bounded(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->patchJson("/api/projects/{$project->id}", ['guaranteed_backers' => 25])
            ->assertOk();

        $this->assertSame(25, $project->fresh()->guaranteed_backers);

        $this->patchJson("/api/projects/{$project->id}", ['guaranteed_backers' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guaranteed_backers');
    }

    public function test_the_funnel_says_where_the_backers_came_from(): void
    {
        $project = Project::factory()->create(['guaranteed_backers' => 12]);
        Sanctum::actingAs($project->user);

        $backers = collect($this->getJson("/api/projects/{$project->id}/dashboard")->json('funnel.steps'))
            ->firstWhere('key', 'backers');

        $this->assertStringContainsString('12 from friends and family', $backers['note']);
    }
}
