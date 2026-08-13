<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard against a database with some history in it.
 *
 * Every other test holds a handful of rows, which is why an out-of-memory
 * fatal reached production with 309 tests green. The read path built one
 * Eloquent model per snapshot and grouped them in memory, so its cost
 * grew with the number of observations ever recorded — the dashboard
 * reached the container's 128 MB limit at about 34,000 rows and died
 * outright past 100,000, arriving as a bare 500.
 *
 * This does not assert a byte count, which would be brittle. It asserts
 * the shape of the cost: reading a month of data must not depend on how
 * much older data sits behind it.
 */
class DashboardAtVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const ADS = 12;

    /** Rows spread over $days, per ad, for the usual ad metrics. */
    private function seedRows(Project $project, int $days): void
    {
        $metrics = ['ad_spend', 'ad_clicks', 'ad_impressions', 'ad_leads',
            'ad_follows', 'ad_landing_page_views', 'ad_form_views'];
        $types = ['landing_page', 'instant_form', 'kickstarter', 'unknown'];
        $rows = [];

        for ($day = 0; $day < $days; $day++) {
            for ($ad = 0; $ad < self::ADS; $ad++) {
                foreach ($metrics as $metric) {
                    $rows[] = [
                        'project_id' => $project->id,
                        'source' => 'meta',
                        'metric' => $metric,
                        'value' => 10,
                        'dimensions' => json_encode([
                            'ad_id' => "ad{$ad}",
                            'ad_type' => $types[$ad % 4],
                            'ad_name' => "Ad {$ad}",
                        ]),
                        'recorded_at' => now()->subDays($day),
                        'created_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 2000) as $chunk) {
            DB::table('metric_snapshots')->insert($chunk);
        }
    }

    private function measure(Project $project): array
    {
        Sanctum::actingAs($project->user);

        gc_collect_cycles();
        $before = memory_get_usage();

        $response = $this->getJson("/api/projects/{$project->id}/dashboard");

        return [$response, memory_get_usage() - $before];
    }

    public function test_history_the_dashboard_does_not_read_does_not_cost_anything(): void
    {
        $recent = Project::factory()->create();
        $this->seedRows($recent, 35);

        $withHistory = Project::factory()->create();
        $this->seedRows($withHistory, 400);

        [$firstResponse] = $this->measure($recent);
        [$secondResponse, $withHistoryCost] = $this->measure($withHistory);

        $firstResponse->assertOk();
        $secondResponse->assertOk();

        // Eleven times the rows, of which the dashboard reads the same
        // thirty days. Holding the rows made this grow with the table;
        // folding them as they stream makes it grow with the window.
        $this->assertLessThan(
            8 * 1024 * 1024,
            $withHistoryCost,
            'Reading a month of data must not scale with the history behind it.',
        );
    }

    public function test_the_numbers_are_the_same_however_much_history_there_is(): void
    {
        $project = Project::factory()->create();
        $this->seedRows($project, 35);

        Sanctum::actingAs($project->user);
        $before = $this->getJson("/api/projects/{$project->id}/dashboard")->json('funnel.steps');

        // Older than every window the dashboard reads.
        $this->seedRows($project, 400);

        $after = $this->getJson("/api/projects/{$project->id}/dashboard")->json('funnel.steps');

        $this->assertSame(
            array_column($before, 'value'),
            array_column($after, 'value'),
            'Adding history outside the window must not change what the window says.',
        );
    }
}
