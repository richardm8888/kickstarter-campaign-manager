<?php

namespace Tests\Feature;

use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use App\Services\Analytics\MetricSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Syncs run hourly and re-report the same days, so the append-only store
 * accumulates repeated observations. Reads must not double-count them.
 */
class MetricAggregationTest extends TestCase
{
    use RefreshDatabase;

    private function series(): MetricSeries
    {
        return app(MetricSeries::class);
    }

    public function test_repeated_reports_of_a_day_do_not_inflate_a_daily_total(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        // Three hourly syncs all reporting yesterday's session total.
        foreach ([180, 190, 205] as $value) {
            $recorder->record($project, 'ga4', 'sessions', $value, now()->subDay());
        }

        // The latest report wins, rather than 180 + 190 + 205.
        $this->assertSame(205.0, $this->series()->sum($project, 'sessions', 30));
        $this->assertSame(205.0, $this->series()->daily($project, 'sessions', 30)->first()['value']);
    }

    public function test_daily_totals_still_sum_across_different_days(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        $recorder->record($project, 'ga4', 'sessions', 100, now()->subDays(2));
        $recorder->record($project, 'ga4', 'sessions', 100, now()->subDays(2)); // duplicate
        $recorder->record($project, 'ga4', 'sessions', 50, now()->subDay());

        $this->assertSame(150.0, $this->series()->sum($project, 'sessions', 30));
    }

    public function test_event_deltas_accumulate_within_a_day(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        // Each signup is a separate event, not a restatement.
        foreach (range(1, 4) as $ignored) {
            $recorder->record($project, 'landing_page', 'signups', 1);
        }

        $this->assertSame(4.0, $this->series()->sum($project, 'signups', 30));
    }

    public function test_level_metrics_report_the_latest_value_not_a_sum(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        $recorder->record($project, 'mailerlite', 'email_subscribers', 100, now()->subDays(2));
        $recorder->record($project, 'mailerlite', 'email_subscribers', 143, now());

        $this->assertSame(143.0, $this->series()->sum($project, 'email_subscribers', 30));
    }

    public function test_dashboard_visitors_are_not_inflated_by_repeat_syncs(): void
    {
        $project = Project::factory()->create();

        foreach (range(1, 5) as $ignored) {
            MetricSnapshot::factory()->for($project)
                ->metric('ga4', 'sessions', 500, now()->subDay())->create();
        }

        \Laravel\Sanctum\Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.visitors.value', 500);
    }
}
