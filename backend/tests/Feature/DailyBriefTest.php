<?php

namespace Tests\Feature;

use App\Models\DailyTask;
use App\Models\Project;
use App\Models\Subscriber;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The daily list. Its value is entirely in what it leaves out, so most of
 * these assert absence: no invented work, no repeats of finished jobs, no
 * reaction to a single day's noise.
 */
class DailyBriefTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $metric, float $value, int $daysAgo, string $source = 'meta'): void
    {
        app(MetricRecorder::class)->record($project, $source, $metric, $value, now()->subDays($daysAgo));
    }

    /**
     * A project past setup and comfortably on pace, so that whatever a
     * test seeds is the only thing the brief has to react to. A modest
     * goal is part of that: a project that cannot fund itself has real
     * work to do, and would drown out everything else.
     */
    private function establishedProject(array $attributes = []): Project
    {
        $project = Project::factory()->create([
            'launch_date' => now()->addDays(45),
            'kickstarter_url' => 'https://www.kickstarter.com/projects/me/game',
            'external_landing_url' => 'https://example.com',
            'funding_goal' => 500_00,
            'average_pledge' => 45_00,
            ...$attributes,
        ]);

        foreach (['meta', 'mailerlite'] as $provider) {
            $project->integrations()->create([
                'provider' => $provider,
                'status' => 'connected',
                'credentials' => ['api_key' => 'x'],
            ]);
        }

        // Both: 'spend' is what the setup checklist counts as having
        // run an ad, 'ad_spend' is the per-ad series the detectors read.
        $this->record($project, 'spend', 5000, 1);
        $this->record($project, 'ad_spend', 5000, 1);

        return $project;
    }

    private function brief(Project $project): array
    {
        Sanctum::actingAs($project->user);

        return $this->getJson("/api/projects/{$project->id}/daily")->assertOk()->json();
    }

    public function test_a_quiet_day_produces_no_work(): void
    {
        // The hardest thing for a recommendation engine to do is nothing.
        $project = $this->establishedProject();

        Subscriber::factory()->count(100)->for($project)->create();
        $this->record($project, 'ks_followers', 40, 1, 'kickstarter');
        $this->record($project, 'email_opens', 30, 3, 'mailerlite');

        $brief = $this->brief($project);

        $this->assertSame([], $brief['tasks']);
    }

    public function test_it_never_returns_more_than_three(): void
    {
        $project = Project::factory()->create(); // every setup step missing

        Subscriber::factory()->count(200)->for($project)->create();

        $this->assertLessThanOrEqual(3, count($this->brief($project)['tasks']));
    }

    public function test_a_list_that_is_not_following_is_the_top_priority(): void
    {
        $project = $this->establishedProject();

        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        $tasks = $this->brief($project)['tasks'];

        $this->assertSame('bottleneck_email_to_follower', $tasks[0]['signal_key']);
        $this->assertSame('high', $tasks[0]['priority']);
        $this->assertStringContainsString('200 subscribers have produced 10 followers', $tasks[0]['why']);
        $this->assertNotEmpty($tasks[0]['action']);
        $this->assertGreaterThan(0, $tasks[0]['effort_minutes']);
    }

    public function test_rising_traffic_with_flat_signups_blames_the_page_not_the_budget(): void
    {
        // The most valuable thing the list does: the instinctive response
        // to poor results is more spend, which here is the one action
        // guaranteed not to help.
        $project = $this->establishedProject();

        foreach (range(1, 28) as $daysAgo) {
            $recent = $daysAgo <= 7;
            $this->record($project, 'ad_landing_page_views', $recent ? 60 : 20, $daysAgo);
            $this->record($project, 'ad_leads', 1, $daysAgo);
        }

        $keys = collect($this->brief($project)['tasks'])->pluck('signal_key');

        $this->assertContains('bottleneck_landing_page', $keys);

        $task = collect($this->brief($project)['tasks'])->firstWhere('signal_key', 'bottleneck_landing_page');
        $this->assertStringNotContainsString('increase your budget', strtolower($task['action']));
    }

    public function test_one_bad_day_is_not_a_trend(): void
    {
        $project = $this->establishedProject();

        foreach (range(1, 28) as $daysAgo) {
            $this->record($project, 'ad_landing_page_views', 40, $daysAgo);
            $this->record($project, 'ad_leads', 8, $daysAgo);
        }

        // Yesterday was terrible. It is still just yesterday.
        $this->record($project, 'ad_leads', 0, 0);
        $this->record($project, 'ad_landing_page_views', 90, 0);

        $keys = collect($this->brief($project)['tasks'])->pluck('signal_key');

        $this->assertNotContains('bottleneck_landing_page', $keys);
    }

    public function test_finishing_a_task_stops_it_coming_back_tomorrow(): void
    {
        $project = $this->establishedProject();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        $task = collect($this->brief($project)['tasks'])->firstWhere('signal_key', 'bottleneck_email_to_follower');

        Sanctum::actingAs($project->user);
        $this->patchJson("/api/projects/{$project->id}/daily/{$task['id']}", ['status' => 'done'])
            ->assertOk();

        // The numbers have not moved — sending the email does not convert
        // anyone the same day — so a naive detector would raise it again.
        $keys = collect($this->brief($project)['tasks'])->pluck('signal_key');

        $this->assertNotContains('bottleneck_email_to_follower', $keys);
    }

    public function test_an_untouched_urgent_task_is_still_there_tomorrow(): void
    {
        $project = $this->establishedProject();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        $first = collect($this->brief($project)['tasks'])->firstWhere('signal_key', 'bottleneck_email_to_follower');
        $second = collect($this->brief($project)['tasks'])->firstWhere('signal_key', 'bottleneck_email_to_follower');

        // Carried forward as the same task, not raised again as new work.
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, $project->dailyTasks()->where('signal_key', 'bottleneck_email_to_follower')->count());
    }

    public function test_a_problem_that_goes_away_stops_being_listed(): void
    {
        $project = $this->establishedProject();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        $this->brief($project);

        // The followers arrive without the creator ticking anything off.
        $this->record($project, 'ks_followers', 150, 0, 'kickstarter');

        $keys = collect($this->brief($project)['tasks'])->pluck('signal_key');

        $this->assertNotContains('bottleneck_email_to_follower', $keys);
    }

    public function test_setup_gaps_come_one_at_a_time(): void
    {
        // A new creator handed six setup tasks does none of them.
        $project = Project::factory()->create(['launch_date' => null, 'kickstarter_url' => null]);

        $setupTasks = collect($this->brief($project)['tasks'])
            ->filter(fn (array $task) => str_starts_with($task['signal_key'], 'setup_'));

        $this->assertCount(1, $setupTasks);
        $this->assertSame('setup_launch_date', $setupTasks->first()['signal_key']);
    }

    public function test_the_brief_says_what_is_healthy(): void
    {
        $project = $this->establishedProject();
        Subscriber::factory()->count(100)->for($project)->create();
        $this->record($project, 'ks_followers', 40, 1, 'kickstarter');

        $brief = $this->brief($project);

        $this->assertNotEmpty($brief['nothing_to_worry_about']);
        $this->assertLessThanOrEqual(3, count($brief['nothing_to_worry_about']));

        $health = collect($brief['funnel_health'])->keyBy('key');
        $this->assertEquals(100, $health['email_subscribers']['value']);
        $this->assertEquals(40, $health['ks_followers']['value']);
        // 40 followers from a list of 100.
        $this->assertEquals(40, $health['email_to_follower']['value']);
    }

    public function test_history_shows_what_was_raised_before(): void
    {
        $project = $this->establishedProject();
        Sanctum::actingAs($project->user);

        DailyTask::factory()->for($project)->create([
            'for_date' => now()->subDays(3)->toDateString(),
            'status' => DailyTask::DONE,
            'completed_at' => now()->subDays(3),
        ]);

        $history = $this->getJson("/api/projects/{$project->id}/daily/history")->assertOk()->json('tasks');

        $this->assertCount(1, $history);
        $this->assertSame('done', $history[0]['status']);
    }

    public function test_the_scheduled_job_writes_a_list_for_every_project(): void
    {
        // Reading regenerates, so this exists only so that a week nobody
        // logged in still leaves a record of what was recommended.
        $project = $this->establishedProject();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        app(\App\Jobs\GenerateDailyBriefs::class)->handle(app(\App\Daily\DailyBrief::class));

        $this->assertDatabaseHas('daily_tasks', [
            'project_id' => $project->id,
            'signal_key' => 'bottleneck_email_to_follower',
            'status' => 'open',
        ]);
    }

    public function test_a_dismissed_task_stays_gone_longer_than_a_finished_one(): void
    {
        // Dismissing is a judgement that it does not matter; finishing is
        // a claim that it is handled. They should not decay alike.
        $project = $this->establishedProject();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10, 1, 'kickstarter');

        $task = collect($this->brief($project)['tasks'])->firstWhere('signal_key', 'bottleneck_email_to_follower');

        Sanctum::actingAs($project->user);
        $this->patchJson("/api/projects/{$project->id}/daily/{$task['id']}", ['status' => 'dismissed']);

        $this->travel(10)->days();

        // A finished task would be back by now; a dismissed one is not.
        $keys = collect($this->brief($project)['tasks'])->pluck('signal_key');
        $this->assertNotContains('bottleneck_email_to_follower', $keys);
    }

    public function test_other_users_cannot_see_or_tick_off_a_projects_tasks(): void
    {
        $project = $this->establishedProject();
        $task = DailyTask::factory()->for($project)->create();

        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->getJson("/api/projects/{$project->id}/daily")->assertForbidden();
        $this->patchJson("/api/projects/{$project->id}/daily/{$task->id}", ['status' => 'done'])
            ->assertForbidden();
    }
}
