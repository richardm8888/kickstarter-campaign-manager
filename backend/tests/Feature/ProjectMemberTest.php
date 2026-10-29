<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sharing a project. The mistakes worth guarding against are the quiet
 * ones: a link that works twice, a member who can lock the owner out, an
 * invitation token handed to someone who should not have it.
 */
class ProjectMemberTest extends TestCase
{
    use RefreshDatabase;

    private function invite(Project $project, string $email = 'sean@example.com'): string
    {
        Sanctum::actingAs($project->user);

        return $this->postJson("/api/projects/{$project->id}/members", ['email' => $email])
            ->assertCreated()
            ->json('invitation.token');
    }

    private function join(string $token, User $user): void
    {
        Sanctum::actingAs($user);
        $this->postJson("/api/invitations/{$token}/accept")->assertOk();
    }

    public function test_an_invited_person_can_join_and_then_work_on_the_project(): void
    {
        $project = Project::factory()->create(['name' => 'Totally Football']);
        $token = $this->invite($project);

        $this->getJson("/api/invitations/{$token}")
            ->assertOk()
            ->assertJsonPath('project.name', 'Totally Football')
            ->assertJsonPath('invited_by', $project->user->name);

        $sean = User::factory()->create(['email' => 'sean@example.com']);
        $this->join($token, $sean);

        $this->getJson("/api/projects/{$project->id}")->assertOk()->assertJsonPath('project.is_owner', false);
        $this->patchJson("/api/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();
        $this->getJson("/api/projects/{$project->id}/launch-tasks")->assertOk();
    }

    public function test_shared_projects_appear_in_the_members_project_list(): void
    {
        $project = Project::factory()->create();
        $sean = User::factory()->create();
        $own = Project::factory()->for($sean)->create();
        $this->join($this->invite($project), $sean);

        $listed = collect($this->getJson('/api/projects')->assertOk()->json('projects'));

        $this->assertEqualsCanonicalizing([$project->id, $own->id], $listed->pluck('id')->all());
        $this->assertFalse($listed->firstWhere('id', $project->id)['is_owner']);
        $this->assertTrue($listed->firstWhere('id', $own->id)['is_owner']);
    }

    public function test_an_invite_link_works_once(): void
    {
        $project = Project::factory()->create();
        $token = $this->invite($project);
        $this->join($token, User::factory()->create());

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/invitations/{$token}/accept")->assertStatus(410);
        $this->getJson("/api/invitations/{$token}")->assertStatus(410);
    }

    public function test_an_expired_link_is_refused(): void
    {
        $project = Project::factory()->create();
        $token = $this->invite($project);
        ProjectInvitation::query()->update(['expires_at' => now()->subMinute()]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/invitations/{$token}/accept")->assertStatus(410);
    }

    public function test_an_unknown_link_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/invitations/not-a-real-token/accept')->assertNotFound();
    }

    public function test_inviting_again_replaces_the_old_link(): void
    {
        $project = Project::factory()->create();
        $first = $this->invite($project);
        $second = $this->invite($project, 'SEAN@example.com ');

        $this->assertNotSame($first, $second);
        $this->assertSame(1, $project->invitations()->count());
        $this->getJson("/api/invitations/{$first}")->assertNotFound();
    }

    public function test_someone_with_access_already_is_not_invited_again(): void
    {
        $project = Project::factory()->create();
        $sean = User::factory()->create(['email' => 'sean@example.com']);
        $this->join($this->invite($project), $sean);

        Sanctum::actingAs($project->user);
        $this->postJson("/api/projects/{$project->id}/members", ['email' => 'sean@example.com'])->assertStatus(422);
    }

    public function test_only_the_owner_manages_access_or_deletes(): void
    {
        $project = Project::factory()->create();
        $sean = User::factory()->create();
        $this->join($this->invite($project), $sean);
        $other = User::factory()->create();
        $project->members()->attach($other->id);

        Sanctum::actingAs($sean);
        $this->postJson("/api/projects/{$project->id}/members", ['email' => 'x@example.com'])->assertForbidden();
        $this->deleteJson("/api/projects/{$project->id}/members/{$other->id}")->assertForbidden();
        $this->deleteJson("/api/projects/{$project->id}/members/{$project->user_id}")->assertForbidden();
        $this->deleteJson("/api/projects/{$project->id}")->assertForbidden();

        $this->assertNotNull(Project::find($project->id));
    }

    public function test_members_do_not_see_invite_links(): void
    {
        $project = Project::factory()->create();
        $sean = User::factory()->create();
        $this->join($this->invite($project), $sean);
        $this->invite($project, 'third@example.com');

        Sanctum::actingAs($sean);
        $this->getJson("/api/projects/{$project->id}/members")
            ->assertOk()
            ->assertJsonPath('is_owner', false)
            ->assertJsonPath('invitations', [])
            ->assertJsonCount(1, 'members');

        Sanctum::actingAs($project->user);
        $this->getJson("/api/projects/{$project->id}/members")
            ->assertJsonCount(1, 'invitations')
            ->assertJsonStructure(['invitations' => [['id', 'email', 'expires_at', 'token']]]);
    }

    public function test_a_member_can_leave_and_the_owner_can_remove_them(): void
    {
        $project = Project::factory()->create();
        $sean = User::factory()->create();
        $this->join($this->invite($project), $sean);

        $this->deleteJson("/api/projects/{$project->id}/members/{$sean->id}")->assertOk();
        $this->getJson("/api/projects/{$project->id}")->assertForbidden();

        $project->members()->attach($sean->id);
        Sanctum::actingAs($project->user);
        $this->deleteJson("/api/projects/{$project->id}/members/{$sean->id}")->assertOk();
        $this->assertFalse($project->hasMember($sean));
    }

    public function test_the_owner_cannot_be_removed(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->deleteJson("/api/projects/{$project->id}/members/{$project->user_id}")->assertStatus(422);
    }

    public function test_cancelled_invitations_stop_working(): void
    {
        $project = Project::factory()->create();
        $token = $this->invite($project);
        $id = $project->invitations()->first()->id;

        $this->deleteJson("/api/projects/{$project->id}/invitations/{$id}")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/invitations/{$token}/accept")->assertNotFound();
    }

    public function test_strangers_still_cannot_see_a_project(): void
    {
        $project = Project::factory()->create();
        $this->invite($project);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/projects/{$project->id}")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/members")->assertForbidden();
    }
}
