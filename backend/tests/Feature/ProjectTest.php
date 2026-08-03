<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_project_provisions_a_landing_page_with_default_sections(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/projects', [
            'name' => 'Totally Football',
            'funding_goal' => 25000_00,
            'average_pledge' => 45_00,
        ]);

        $response->assertCreated();

        $project = Project::firstWhere('slug', 'totally-football');
        $this->assertNotNull($project);
        $this->assertNotNull($project->landingPage);

        $types = $project->landingPage->sections->pluck('type');
        $this->assertContains('hero', $types);
        $this->assertContains('email_capture', $types);
        $this->assertContains('vip_upgrade', $types);
    }

    public function test_users_cannot_see_other_users_projects(): void
    {
        $project = Project::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/dashboard")->assertForbidden();
    }

    public function test_a_user_can_update_their_project(): void
    {
        $project = Project::factory()->create();

        Sanctum::actingAs($project->user);

        $this->patchJson("/api/projects/{$project->id}", ['funding_goal' => 50000_00])
            ->assertOk()
            ->assertJsonPath('project.funding_goal', 50000_00);
    }

    public function test_a_user_can_delete_their_project(): void
    {
        $project = Project::factory()->create();

        Sanctum::actingAs($project->user);

        $this->deleteJson("/api/projects/{$project->id}")->assertOk();
        $this->assertDatabaseMissing('projects', ['id' => $project->id]);
    }
}
