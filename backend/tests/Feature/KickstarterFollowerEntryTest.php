<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kickstarter publishes no follower count, so the creator reads it off
 * their own dashboard and records it here. It is the only path that
 * always works, and followers are the segment worth the most.
 */
class KickstarterFollowerEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_creator_can_record_a_follower_count(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/kickstarter-followers", ['count' => 480])
            ->assertCreated()
            ->assertJsonPath('count', 480);

        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'metric' => 'ks_followers',
            'source' => 'manual',
            'value' => 480,
        ]);
    }

    public function test_entries_accumulate_into_a_growth_curve(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        foreach ([120, 310, 480] as $count) {
            $this->postJson("/api/projects/{$project->id}/kickstarter-followers", ['count' => $count]);
        }

        // Append-only: three readings, none overwritten.
        $this->assertSame(3, $project->metricSnapshots()->where('metric', 'ks_followers')->count());
    }

    public function test_a_recorded_count_reaches_the_dashboard(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/kickstarter-followers", ['count' => 480]);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.ks_followers.value', 480);
    }

    public function test_nonsense_counts_are_rejected(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/kickstarter-followers", ['count' => -5])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('count');
    }

    public function test_other_users_cannot_record_against_a_project(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/projects/{$project->id}/kickstarter-followers", ['count' => 10])
            ->assertForbidden();
    }
}
