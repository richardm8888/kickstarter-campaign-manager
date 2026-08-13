<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard against the kind of data a database actually holds.
 *
 * Every other test builds exactly the rows the code under test expects.
 * A live database is months of rows written by older versions: metrics
 * that were renamed, dimensions that were added later, ad types that no
 * longer exist, and columns that were nullable before they were not.
 * The dashboard 500ed on production data while 301 tests passed, which
 * is the gap these cover.
 */
class DashboardSurvivesRealDataTest extends TestCase
{
    use RefreshDatabase;

    private function dashboard(Project $project): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($project->user);

        return $this->getJson("/api/projects/{$project->id}/dashboard");
    }

    private function record(Project $project, string $source, string $metric, float $value, ?array $dimensions = null): void
    {
        app(MetricRecorder::class)->record($project, $source, $metric, $value, now()->subDay(), $dimensions);
    }

    public function test_an_ad_type_this_version_has_never_heard_of(): void
    {
        $project = Project::factory()->create();

        // Written by a version that classified ads differently.
        $this->record($project, 'meta', 'ad_spend', 40.0, ['ad_id' => '1', 'ad_type' => 'carousel_retired_in_2024']);
        $this->record($project, 'meta', 'ad_clicks', 90, ['ad_id' => '1', 'ad_type' => 'carousel_retired_in_2024']);

        $this->dashboard($project)->assertOk();
    }

    public function test_ad_rows_with_no_dimensions_at_all(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'meta', 'ad_spend', 40.0);
        $this->record($project, 'meta', 'ad_clicks', 90);
        $this->record($project, 'meta', 'ad_leads', 4);

        $this->dashboard($project)->assertOk();
    }

    public function test_an_ad_row_missing_the_dimension_everything_is_keyed_on(): void
    {
        $project = Project::factory()->create();

        // ad_id arrived in a later version; older rows carry only a name.
        $this->record($project, 'meta', 'ad_spend', 40.0, ['ad_name' => 'Old creative']);
        $this->record($project, 'meta', 'ad_clicks', 90, ['ad_name' => 'Old creative']);

        $this->dashboard($project)->assertOk();
    }

    public function test_a_project_with_no_funding_goal(): void
    {
        $project = Project::factory()->create(['funding_goal' => 0]);

        $this->record($project, 'ga4', 'sessions', 368);

        $this->dashboard($project)->assertOk();
    }

    public function test_a_project_with_no_average_pledge(): void
    {
        $project = Project::factory()->create(['average_pledge' => 0]);

        $this->dashboard($project)->assertOk();
    }

    public function test_saved_assumptions_from_an_older_shape(): void
    {
        $project = Project::factory()->create([
            // Keys this version does not use, and the one it does missing.
            'forecast_assumptions' => ['ad_spend' => 50000, 'conversion' => 0.03],
        ]);

        $this->dashboard($project)->assertOk();
    }

    public function test_metrics_that_no_longer_mean_anything_here(): void
    {
        $project = Project::factory()->create();

        // A metric name that was retired: it must be ignored, not summed
        // into a bucket that was never sized for it.
        $this->record($project, 'meta', 'ad_conversions_legacy', 12, ['ad_id' => '1', 'ad_type' => 'landing_page']);
        $this->record($project, 'meta', 'ad_spend', 40.0, ['ad_id' => '1', 'ad_type' => 'landing_page']);

        $this->dashboard($project)->assertOk();
    }

    public function test_a_project_shaped_like_a_real_one(): void
    {
        $project = Project::factory()->create([
            'launch_date' => now()->addDays(55),
            'funding_goal' => 15_000_00,
            'average_pledge' => 45_00,
        ]);

        $this->record($project, 'ga4', 'sessions', 368);
        $this->record($project, 'meta', 'spend', 114.0);
        $this->record($project, 'meta', 'cpc', 0.31);
        $this->record($project, 'kickstarter', 'ks_followers', 38);

        foreach (['landing_page', 'instant_form', 'kickstarter', 'unknown'] as $i => $type) {
            $this->record($project, 'meta', 'ad_spend', 28.5, ['ad_id' => (string) $i, 'ad_type' => $type]);
            $this->record($project, 'meta', 'ad_clicks', 92, ['ad_id' => (string) $i, 'ad_type' => $type]);
            $this->record($project, 'meta', 'ad_leads', 33, ['ad_id' => (string) $i, 'ad_type' => $type]);
        }

        $this->dashboard($project)
            ->assertOk()
            ->assertJsonPath('cards.ks_followers.value', 38);
    }
}
