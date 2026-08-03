<?php

namespace Tests\Feature;

use App\Forecasting\LaunchPlan;
use App\Models\Project;
use App\Models\Subscriber;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaunchPlanTest extends TestCase
{
    use RefreshDatabase;

    private function project(int $daysToLaunch = 30): Project
    {
        $project = Project::factory()->create([
            'average_pledge' => 5000,
            'funding_goal' => 1000000,
            'launch_date' => now()->addDays($daysToLaunch)->toDateString(),
        ]);

        // Measured: £1 clicks, 20% of them subscribe.
        $recorder = app(MetricRecorder::class);
        $recorder->record($project, 'meta', 'cpc', 1.0);
        $recorder->record($project, 'meta', 'ad_clicks', 1000, now()->subDay(), ['ad_id' => '1']);
        $recorder->record($project, 'meta', 'ad_leads', 200, now()->subDay(), ['ad_id' => '1']);

        return $project;
    }

    private function plan(Project $project, int $budget): array
    {
        return app(LaunchPlan::class)->build($project, $budget);
    }

    public function test_it_needs_a_launch_date(): void
    {
        $project = Project::factory()->create(['launch_date' => null]);

        $this->assertFalse($this->plan($project, 100000)['has_launch_date']);
    }

    public function test_the_whole_budget_is_scheduled_before_launch(): void
    {
        $plan = $this->plan($this->project(30), 300000);

        // Every penny is allocated across the days ahead, and not one more.
        $this->assertSame(300000, $plan['summary']['total_spend']);
        $this->assertSame(300000, array_sum(array_column($plan['days'], 'recommended_spend')));
        $this->assertSame(30, $plan['days_remaining']);
    }

    public function test_daily_rounding_never_drifts_past_the_budget(): void
    {
        // A long run-up is where per-day rounding would accumulate.
        foreach ([150000, 99999, 12345] as $budget) {
            $plan = $this->plan($this->project(109), $budget);

            $this->assertSame($budget, $plan['summary']['total_spend'], "budget {$budget} drifted");
            $this->assertSame($budget, (int) end($plan['days'])['cumulative_spend']);
        }
    }

    public function test_the_final_week_gets_a_much_bigger_daily_budget(): void
    {
        $summary = $this->plan($this->project(30), 300000)['summary'];

        $this->assertGreaterThan(
            $summary['daily_spend'] * 2,
            $summary['final_week_daily_spend'],
            'the week before launch should see a real spike',
        );
    }

    public function test_it_projects_the_list_growing_to_launch_day(): void
    {
        $project = $this->project(30);
        Subscriber::factory()->for($project)->count(100)->create();

        $plan = $this->plan($project, 300000);

        // £3,000 at £1 a click is 3,000 visitors; 20% subscribe.
        $this->assertSame(100, $plan['summary']['starting_list']);
        $this->assertEqualsWithDelta(700, $plan['summary']['projected_at_launch'], 5);

        $lastDay = end($plan['days']);
        $this->assertSame($project->launch_date->toDateString(), $lastDay['date']);
        $this->assertEqualsWithDelta(700, $lastDay['projected_subscribers'], 5);
    }

    public function test_it_says_whether_the_plan_reaches_the_target_list(): void
    {
        $project = $this->project(30);

        // £10,000 goal at £50 = 200 backers; at 15% that needs 1,334 subscribers.
        $short = $this->plan($project, 300000)['summary'];
        $this->assertSame(1334, $short['target_list']);
        $this->assertFalse($short['on_track']);
        $this->assertGreaterThan(0, $short['shortfall']);

        $generous = $this->plan($project, 900000)['summary'];
        $this->assertTrue($generous['on_track']);
    }

    public function test_past_days_carry_actuals_and_future_days_carry_projections(): void
    {
        $project = $this->project(30);
        Subscriber::factory()->for($project)->count(40)->create(['created_at' => now()->subDays(3)]);

        $days = collect($this->plan($project, 300000)['days']);

        $past = $days->firstWhere('date', now()->subDay()->toDateString());
        $future = $days->firstWhere('date', now()->addDays(5)->toDateString());

        $this->assertNotNull($past['actual_subscribers']);
        $this->assertNull($past['projected_subscribers']);
        $this->assertNull($future['actual_subscribers']);
        $this->assertNotNull($future['projected_subscribers']);
        $this->assertSame(0, $past['recommended_spend'], 'no budget is planned for days already gone');
    }

    public function test_the_plan_is_returned_by_the_forecast_endpoint(): void
    {
        $project = $this->project(21);
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/forecast?planned_ad_spend=200000")
            ->assertOk()
            ->assertJsonPath('plan.has_launch_date', true)
            ->assertJsonPath('plan.days_remaining', 21)
            ->assertJsonStructure([
                'plan' => [
                    'summary' => ['projected_at_launch', 'target_list', 'on_track', 'final_week_daily_spend'],
                    'days' => [['date', 'recommended_spend', 'projected_subscribers', 'actual_subscribers']],
                ],
            ]);
    }
}
