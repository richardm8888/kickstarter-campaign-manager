<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Follower counts on a chart.
 *
 * They were recorded hourly from the day the Kickstarter page was linked
 * and never plotted — the dashboard showed today's number, which says
 * nothing about whether it is climbing, flat or stalled. Every other
 * metric in the app had a graph; the one the launch rests on did not.
 */
class AudienceOverTimeTest extends TestCase
{
    use RefreshDatabase;

    private function followers(Project $project, array $byDaysAgo): void
    {
        $recorder = app(MetricRecorder::class);

        foreach ($byDaysAgo as $daysAgo => $count) {
            $recorder->record($project, 'kickstarter', 'ks_followers', $count, now()->subDays($daysAgo));
        }
    }

    public function test_followers_come_back_as_a_series_to_plot(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->followers($project, [4 => 40, 3 => 44, 2 => 51, 1 => 58, 0 => 64]);

        $response = $this->getJson("/api/projects/{$project->id}/analytics?category=audience&days=30")
            ->assertOk()
            ->assertJsonPath('metrics.0.metric', 'ks_followers')
            ->assertJsonPath('metrics.0.label', 'Kickstarter followers');

        $series = $response->json('metrics.0.series');

        $this->assertCount(5, $series);
        $this->assertSame(40, $series[0]["value"]);
        $this->assertSame(64, $series[4]["value"]);
    }

    public function test_a_running_count_reports_where_it_stands_not_the_sum_of_every_day(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->followers($project, [2 => 51, 1 => 58, 0 => 64]);

        // 51 + 58 + 64 would be 173 followers, which is the kind of
        // number a level metric produces when it is summed by mistake.
        $this->getJson("/api/projects/{$project->id}/analytics?category=audience&days=30")
            ->assertOk()
            ->assertJsonPath('metrics.0.total', 64)
            ->assertJsonPath('metrics.0.latest', 64)
            ->assertJsonPath('metrics.0.aggregation', 'level');
    }

    public function test_the_hourly_sync_does_not_put_a_day_on_the_chart_twice(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $recorder = app(MetricRecorder::class);

        // Three reads of the same day, as the hourly job produces.
        foreach ([60, 62, 64] as $count) {
            $recorder->record($project, 'kickstarter', 'ks_followers', $count, now());
        }

        $series = $this->getJson("/api/projects/{$project->id}/analytics?category=audience&days=30")
            ->assertOk()
            ->json('metrics.0.series');

        $this->assertCount(1, $series);
        $this->assertSame(64, $series[0]["value"], 'The last read of a day is the day.');
    }

    public function test_the_audience_view_carries_the_list_and_the_vips_too(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $metrics = $this->getJson("/api/projects/{$project->id}/analytics?category=audience")
            ->assertOk()
            ->json('metrics');

        $this->assertSame(
            ['ks_followers', 'email_subscribers', 'email_vip_subscribers'],
            array_column($metrics, 'metric'),
        );
    }

    public function test_the_email_extras_do_not_follow_the_audience_view(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        // Follower lift and the automations table belong to the Email
        // tab; this one is charts.
        $this->getJson("/api/projects/{$project->id}/analytics?category=audience")
            ->assertOk()
            ->assertJsonPath('follower_lift', null)
            ->assertJsonPath('email_performance', null)
            ->assertJsonPath('breakdown', null);
    }
}
