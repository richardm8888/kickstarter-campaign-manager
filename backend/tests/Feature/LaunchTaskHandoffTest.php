<?php

namespace Tests\Feature;

use App\Models\LaunchTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The prompt that hands a task to a coding agent. It has to carry
 * everything an agent starting cold needs, and nothing that would get
 * something wrong published — above all a launch date not yet confirmed.
 */
class LaunchTaskHandoffTest extends TestCase
{
    use RefreshDatabase;

    private function prompt(Project $project, LaunchTask $task): string
    {
        Sanctum::actingAs($project->user);

        return $this->getJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/handoff")->assertOk()->json('prompt');
    }

    private function project(array $attributes = []): Project
    {
        return Project::factory()->create([
            'name' => 'Totally Football',
            'external_landing_url' => 'https://www.totallyfootballgame.co.uk',
            'website_repo' => 'richardm8888/totally-football',
            'assets_repo' => 'richardm8888/totally-football-assets',
            'content_brief' => 'Two brothers, a football board game.',
            'team' => ['Richard', 'Sean'],
            ...$attributes,
        ]);
    }

    public function test_a_website_task_says_which_repository_and_how_to_deliver(): void
    {
        $project = $this->project(['launch_date' => '2027-01-26', 'launch_date_confirmed' => true]);
        $task = LaunchTask::factory()->for($project)->create([
            'title' => 'Retitle the homepage',
            'category' => 'website',
            'details' => "Lead with \"football board game\".\n\nWhy: 642 impressions at position 8.5.",
            'offset_days' => -60,
            'assignee' => 'Sean',
            'content_type' => null,
        ]);

        $prompt = $this->prompt($project, $task);

        $this->assertStringContainsString('Retitle the homepage', $prompt);
        $this->assertStringContainsString('642 impressions at position 8.5', $prompt);
        $this->assertStringContainsString('Work in the richardm8888/totally-football repository', $prompt);
        $this->assertStringContainsString('open a pull request', $prompt);
        $this->assertStringContainsString('richardm8888/totally-football-assets', $prompt);
        $this->assertStringContainsString('Two brothers, a football board game.', $prompt);
        $this->assertStringContainsString('Owner: Sean', $prompt);
        // 60 days before 26 January 2027.
        $this->assertStringContainsString('Due: Friday 27 November 2026', $prompt);
        $this->assertStringContainsString('26 January 2027 (confirmed)', $prompt);
    }

    public function test_a_provisional_launch_date_is_marked_as_not_for_publishing(): void
    {
        $project = $this->project(['launch_date' => '2027-01-26', 'launch_date_confirmed' => false]);
        $task = LaunchTask::factory()->for($project)->create(['category' => 'website']);

        $prompt = $this->prompt($project, $task);

        $this->assertStringContainsString('NOT confirmed', $prompt);
        $this->assertStringContainsString('do not publish it', $prompt);
    }

    public function test_the_latest_draft_is_included_as_the_starting_point(): void
    {
        $project = $this->project();
        $task = LaunchTask::factory()->for($project)->create(['category' => 'email', 'content_type' => 'email']);
        $task->drafts()->create(['content' => 'Old draft']);
        $task->drafts()->create(['content' => 'Subject: The newest draft']);

        $prompt = $this->prompt($project, $task);

        $this->assertStringContainsString('Subject: The newest draft', $prompt);
        $this->assertStringNotContainsString('Old draft', $prompt);
        $this->assertStringContainsString('Do not send, post or publish anything yourself', $prompt);
    }

    public function test_without_a_repository_a_website_task_still_gets_a_safe_prompt(): void
    {
        $project = $this->project(['website_repo' => null]);
        $task = LaunchTask::factory()->for($project)->create(['category' => 'website', 'content_type' => null]);

        $prompt = $this->prompt($project, $task);

        $this->assertStringNotContainsString('Work in the', $prompt);
        $this->assertStringContainsString('Never invent quotes', $prompt);
    }

    public function test_repositories_are_saved_with_the_plan_settings(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->putJson("/api/projects/{$project->id}/launch-tasks/settings", [
            'website_repo' => 'richardm8888/totally-football',
            'assets_repo' => 'richardm8888/totally-football-assets',
        ])->assertOk();

        $this->getJson("/api/projects/{$project->id}/launch-tasks")
            ->assertJsonPath('website_repo', 'richardm8888/totally-football')
            ->assertJsonPath('assets_repo', 'richardm8888/totally-football-assets');
    }

    public function test_another_creators_task_is_out_of_reach(): void
    {
        $project = $this->project();
        $task = LaunchTask::factory()->for($project)->create();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/projects/{$project->id}/launch-tasks/{$task->id}/handoff")->assertForbidden();
    }
}
