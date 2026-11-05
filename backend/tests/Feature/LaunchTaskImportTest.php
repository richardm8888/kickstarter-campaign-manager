<?php

namespace Tests\Feature;

use App\Models\LaunchTask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Importing a pasted list. What matters is that a list is all-or-nothing,
 * that pasting it twice changes nothing, and that every mistake is
 * reported against the line it is on.
 */
class LaunchTaskImportTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['launch_date' => '2027-01-26']);
        Sanctum::actingAs($this->project->user);
    }

    private function import(string $text, bool $preview = false): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/projects/{$this->project->id}/launch-tasks/import", compact('text', 'preview'));
    }

    public function test_a_full_line_becomes_a_task(): void
    {
        $this->import('Pitch Punchboard | Outreach | Outreach email | -84 | Richard | Adam reviewed Superclub')
            ->assertOk()
            ->assertJsonPath('saved', true);

        $task = $this->project->launchTasks()->sole();
        $this->assertSame('Pitch Punchboard', $task->title);
        $this->assertSame('outreach', $task->category);
        $this->assertSame('outreach', $task->phase);
        $this->assertSame('outreach_email', $task->content_type);
        $this->assertSame(-84, $task->offset_days);
        $this->assertSame('Richard', $task->assignee);
        $this->assertSame('Adam reviewed Superclub', $task->details);
    }

    public function test_only_a_title_is_needed(): void
    {
        $this->import('Order business cards')->assertOk();

        $task = $this->project->launchTasks()->sole();
        $this->assertNull($task->content_type);
        $this->assertNull($task->offset_days);
        $this->assertNull($task->assignee);
    }

    public function test_fixed_dates_land_in_the_right_phase(): void
    {
        $this->import(implode("\n", [
            'Black Friday email | Email | Mailing list email | 2026-11-27',
            'One week to go post | social | social posts | 2027-01-19',
            'Launch party | kickstarter | | launch',
            'Thank-you update | kickstarter | kickstarter update | +3',
        ]))->assertOk();

        $phases = $this->project->launchTasks()->orderBy('id')->pluck('phase', 'title');
        $this->assertSame('audience', $phases['Black Friday email']);
        $this->assertSame('countdown', $phases['One week to go post']);
        $this->assertSame('launch', $phases['Launch party']);
        $this->assertSame('campaign', $phases['Thank-you update']);
        $this->assertSame('2026-11-27', $this->project->launchTasks()->where('title', 'Black Friday email')->first()->due_on->toDateString());
    }

    public function test_done_lines_tick_off_playbook_steps_by_key_or_title(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/launch-tasks/playbook");

        $this->import("done: welcome_email\ndone: Put \"Follow on Kickstarter\" everywhere")->assertOk();

        $done = $this->project->launchTasks()->where('status', LaunchTask::DONE)->pluck('template_key')->sort()->values()->all();
        $this->assertSame(['follow_buttons', 'welcome_email'], $done);
        $this->assertNotNull($this->project->launchTasks()->where('template_key', 'welcome_email')->value('completed_at'));
    }

    public function test_one_bad_line_saves_nothing_and_names_the_line(): void
    {
        $this->import("Good task | email\n\nAnother good one\nBroken | not-a-category")
            ->assertOk()
            ->assertJsonPath('saved', false)
            ->assertJsonPath('errors.0.line', 4);

        $this->assertSame(0, $this->project->launchTasks()->count());
    }

    public function test_an_unknown_done_reference_is_an_error(): void
    {
        $this->import("New task\ndone: no_such_step")
            ->assertOk()
            ->assertJsonPath('saved', false)
            ->assertJsonPath('errors.0.line', 2);

        $this->assertSame(0, $this->project->launchTasks()->count());
    }

    public function test_unreadable_due_dates_and_content_types_are_reported(): void
    {
        $this->import("A | email | | next tuesday\nB | email | haiku | -7")
            ->assertOk()
            ->assertJsonPath('saved', false)
            ->assertJsonCount(2, 'errors')
            ->assertJsonPath('errors.0.line', 1)
            ->assertJsonPath('errors.1.line', 2);
    }

    public function test_preview_saves_nothing(): void
    {
        $this->postJson("/api/projects/{$this->project->id}/launch-tasks/playbook");

        $this->import("Pitch Punchboard | outreach\ndone: welcome_email", preview: true)
            ->assertOk()
            ->assertJsonPath('saved', false)
            ->assertJsonCount(1, 'tasks')
            ->assertJsonCount(1, 'done');

        $this->assertFalse($this->project->launchTasks()->where('title', 'Pitch Punchboard')->exists());
        $this->assertSame(0, $this->project->launchTasks()->where('status', LaunchTask::DONE)->count());
    }

    public function test_pasting_the_same_list_twice_adds_nothing_the_second_time(): void
    {
        $list = "Pitch Punchboard | outreach\nPitch Thumb Culture | outreach";

        $this->import($list)->assertOk();
        $this->import($list)->assertOk()->assertJsonCount(0, 'tasks')->assertJsonCount(2, 'skipped');

        $this->assertSame(2, $this->project->launchTasks()->count());
    }

    public function test_comments_and_blank_lines_are_ignored_and_details_may_contain_pipes(): void
    {
        $this->import("# Reviewers\n\nPitch TableTok | outreach | outreach email | -84 | Sean | Covered Superclub | liked the components")
            ->assertOk();

        $this->assertSame('Covered Superclub | liked the components', $this->project->launchTasks()->sole()->details);
    }

    public function test_another_creators_project_is_out_of_reach(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->import('Sneaky task')->assertForbidden();
    }
}
