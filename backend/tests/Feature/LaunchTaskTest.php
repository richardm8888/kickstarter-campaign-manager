<?php

namespace Tests\Feature;

use App\AI\Contracts\AiProvider;
use App\Launch\Playbook;
use App\Models\LaunchTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The launch plan: a to-do list timed against launch day, with drafts
 * written on request. Most of what can go wrong here is quiet — a plan
 * loaded twice, a date that does not move, a provisional launch date
 * leaking into something meant for the public.
 */
class LaunchTaskTest extends TestCase
{
    use RefreshDatabase;

    private function signedInProject(array $attributes = []): Project
    {
        $project = Project::factory()->create($attributes);
        Sanctum::actingAs($project->user);

        return $project;
    }

    /** Records what it was asked, and answers with a fixed reply. */
    private function fakeAi(?string $reply = 'Draft text'): object
    {
        $fake = new class($reply) implements AiProvider
        {
            public array $calls = [];

            public function __construct(private ?string $reply) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function complete(string $system, string $prompt, int $maxTokens = 1024): ?string
            {
                $this->calls[] = compact('system', 'prompt');

                return $this->reply;
            }
        };

        $this->app->instance(AiProvider::class, $fake);

        return $fake;
    }

    public function test_loading_the_playbook_twice_does_not_duplicate_the_plan(): void
    {
        $project = $this->signedInProject();

        $this->postJson("/api/projects/{$project->id}/launch-tasks/playbook")
            ->assertOk()
            ->assertJson(['added' => count(Playbook::steps())]);

        $this->postJson("/api/projects/{$project->id}/launch-tasks/playbook")
            ->assertOk()
            ->assertJson(['added' => 0]);

        $this->assertSame(count(Playbook::steps()), $project->launchTasks()->count());
    }

    public function test_reloading_the_playbook_keeps_the_creators_changes_and_restores_deleted_steps(): void
    {
        $project = $this->signedInProject();
        $this->postJson("/api/projects/{$project->id}/launch-tasks/playbook");

        $edited = $project->launchTasks()->where('template_key', 'bgg_listing')->first();
        $edited->update(['title' => 'Our own wording', 'status' => LaunchTask::DONE]);
        $project->launchTasks()->where('template_key', 'press_kit')->delete();

        $this->postJson("/api/projects/{$project->id}/launch-tasks/playbook")->assertJson(['added' => 1]);

        $this->assertSame('Our own wording', $edited->fresh()->title);
        $this->assertSame(LaunchTask::DONE, $edited->fresh()->status);
    }

    public function test_due_dates_follow_the_launch_date_when_it_moves(): void
    {
        $project = $this->signedInProject(['launch_date' => '2027-01-26']);
        LaunchTask::factory()->for($project)->create(['offset_days' => -7]);

        $this->getJson("/api/projects/{$project->id}/launch-tasks")
            ->assertJsonPath('tasks.0.due_date', '2027-01-19');

        $project->update(['launch_date' => '2027-02-09']);

        $this->getJson("/api/projects/{$project->id}/launch-tasks")
            ->assertJsonPath('tasks.0.due_date', '2027-02-02');
    }

    public function test_there_is_no_due_date_without_a_launch_date_unless_one_is_fixed(): void
    {
        $project = $this->signedInProject(['launch_date' => null]);
        LaunchTask::factory()->for($project)->create(['offset_days' => -7, 'phase' => 'audience']);
        LaunchTask::factory()->for($project)->create(['offset_days' => null, 'due_on' => '2026-11-27', 'phase' => 'countdown']);

        $this->getJson("/api/projects/{$project->id}/launch-tasks")
            ->assertJsonPath('tasks.0.due_date', null)
            ->assertJsonPath('tasks.1.due_date', '2026-11-27');
    }

    public function test_tasks_can_be_assigned_and_completed(): void
    {
        $project = $this->signedInProject();
        $task = LaunchTask::factory()->for($project)->create();

        $this->patchJson("/api/projects/{$project->id}/launch-tasks/{$task->id}", [
            'assignee' => 'Sean',
            'status' => LaunchTask::DONE,
        ])->assertOk()->assertJsonPath('task.assignee', 'Sean');

        $this->assertNotNull($task->fresh()->completed_at);

        $this->patchJson("/api/projects/{$project->id}/launch-tasks/{$task->id}", ['status' => LaunchTask::TODO]);

        $this->assertNull($task->fresh()->completed_at);
    }

    public function test_custom_tasks_can_be_added_and_deleted(): void
    {
        $project = $this->signedInProject();

        $id = $this->postJson("/api/projects/{$project->id}/launch-tasks", [
            'title' => 'Black Friday email',
            'phase' => 'audience',
            'category' => 'email',
            'due_on' => '2026-11-27',
            'content_type' => 'email',
        ])->assertCreated()->json('task.id');

        $this->deleteJson("/api/projects/{$project->id}/launch-tasks/{$id}")->assertOk();
        $this->assertSame(0, $project->launchTasks()->count());
    }

    public function test_another_creators_plan_is_out_of_reach(): void
    {
        $project = Project::factory()->create();
        $task = LaunchTask::factory()->for($project)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$project->id}/launch-tasks")->assertForbidden();
        $this->patchJson("/api/projects/{$project->id}/launch-tasks/{$task->id}", ['status' => 'done'])->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")->assertForbidden();
    }

    public function test_a_task_from_another_project_is_not_found(): void
    {
        $project = $this->signedInProject();
        $other = LaunchTask::factory()->create();

        $this->patchJson("/api/projects/{$project->id}/launch-tasks/{$other->id}", ['status' => 'done'])->assertNotFound();
    }

    public function test_team_and_brief_are_saved_trimmed(): void
    {
        $project = $this->signedInProject();

        $this->putJson("/api/projects/{$project->id}/launch-tasks/settings", [
            'team' => [' Richard ', 'Sean', ''],
            'content_brief' => 'Two brothers, a football board game.',
            'launch_date_confirmed' => true,
        ])->assertOk();

        $project->refresh();
        $this->assertSame(['Richard', 'Sean'], $project->team);
        $this->assertTrue($project->launch_date_confirmed);
    }

    public function test_writing_a_draft_saves_it_and_uses_the_brief_and_notes(): void
    {
        $ai = $this->fakeAi('Subject: Hello');
        $project = $this->signedInProject(['content_brief' => 'Our voice is cheeky.']);
        $task = LaunchTask::factory()->for($project)->create(['content_type' => 'email']);

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts", ['notes' => 'Mention the trailer'])
            ->assertCreated()
            ->assertJsonPath('draft.content', 'Subject: Hello');

        $this->assertSame(1, $task->drafts()->count());
        $this->assertStringContainsString('Our voice is cheeky.', $ai->calls[0]['system']);
        $this->assertStringContainsString('Mention the trailer', $ai->calls[0]['prompt']);
    }

    public function test_a_provisional_launch_date_is_never_given_to_the_writer(): void
    {
        $ai = $this->fakeAi();
        $project = $this->signedInProject(['launch_date' => '2027-01-26', 'launch_date_confirmed' => false]);
        $task = LaunchTask::factory()->for($project)->create(['content_type' => 'email']);

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")->assertCreated();

        $sent = $ai->calls[0]['system'].$ai->calls[0]['prompt'];
        $this->assertStringNotContainsString('2027', $sent);
        $this->assertStringNotContainsString('January', $sent);
        $this->assertStringContainsString('do not mention any launch date', $sent);
    }

    public function test_a_confirmed_launch_date_is_given_to_the_writer(): void
    {
        $ai = $this->fakeAi();
        $project = $this->signedInProject(['launch_date' => '2027-01-26', 'launch_date_confirmed' => true]);
        $task = LaunchTask::factory()->for($project)->create(['content_type' => 'email']);

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")->assertCreated();

        $this->assertStringContainsString('Tuesday 26 January 2027', $ai->calls[0]['system']);
    }

    public function test_writing_says_plainly_when_ai_is_not_set_up(): void
    {
        config(['services.anthropic.key' => null]);
        $this->app->forgetInstance(AiProvider::class);

        $project = $this->signedInProject();
        $task = LaunchTask::factory()->for($project)->create();

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")
            ->assertStatus(503)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'ANTHROPIC_API_KEY'));

        $this->assertSame(0, $task->drafts()->count());
    }

    public function test_a_failed_completion_saves_nothing(): void
    {
        $this->fakeAi(null);
        $project = $this->signedInProject();
        $task = LaunchTask::factory()->for($project)->create();

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")->assertStatus(502);

        $this->assertSame(0, $task->drafts()->count());
    }

    public function test_a_task_with_nothing_to_write_is_refused(): void
    {
        $this->fakeAi();
        $project = $this->signedInProject();
        $task = LaunchTask::factory()->for($project)->create(['content_type' => null]);

        $this->postJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/drafts")->assertStatus(422);
    }

    public function test_every_playbook_step_is_valid(): void
    {
        $keys = [];

        foreach (Playbook::steps() as $step) {
            $this->assertArrayHasKey($step['phase'], Playbook::PHASES, $step['key']);
            $this->assertArrayHasKey($step['category'], Playbook::CATEGORIES, $step['key']);
            if ($step['content_type'] !== null) {
                $this->assertArrayHasKey($step['content_type'], \App\Launch\ContentWriter::TYPES, $step['key']);
            }
            $this->assertLessThanOrEqual(60, strlen($step['key']));
            $keys[] = $step['key'];
        }

        $this->assertSame(count($keys), count(array_unique($keys)), 'Playbook keys must be unique');
    }
}
