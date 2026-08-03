<?php

namespace Tests\Feature;

use App\Actions\RunIntegrationSync;
use App\Models\Integration;
use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaActionMappingTest extends TestCase
{
    use RefreshDatabase;

    private function connect(Project $project, array $settings = []): void
    {
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
            'settings' => $settings,
        ]);
    }

    private function insights(array $actions): array
    {
        return [
            'data' => [[
                'ad_id' => '1',
                'ad_name' => 'Video demo',
                'campaign_name' => 'Pre-launch',
                'adset_name' => 'Warm',
                'date_stop' => now()->subDay()->toDateString(),
                'spend' => '20.00',
                'impressions' => '1000',
                'clicks' => '50',
                'actions' => $actions,
            ]],
        ];
    }

    private function leadsRecorded(Project $project): float
    {
        return (float) MetricSnapshot::where('project_id', $project->id)
            ->where('metric', 'ad_leads')
            ->sum('value');
    }

    public function test_an_aggregate_zero_no_longer_hides_the_real_pixel_count(): void
    {
        // Meta lists the aggregate first with 0, then the real pixel count.
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'lead', 'value' => '0'],
            ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '17'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(17.0, $this->leadsRecorded($project));
    }

    public function test_the_same_conversion_reported_twice_is_not_double_counted(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'lead', 'value' => '12'],
            ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '12'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(12.0, $this->leadsRecorded($project));
    }

    public function test_instant_form_leads_are_counted(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'leadgen_grouped', 'value' => '9'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(9.0, $this->leadsRecorded($project));
    }

    public function test_a_custom_conversion_is_ignored_until_it_is_mapped(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'offsite_conversion.custom.987654321', 'value' => '23'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(0.0, $this->leadsRecorded($project));
    }

    public function test_a_mapped_custom_conversion_is_counted_as_signups(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'offsite_conversion.custom.987654321', 'value' => '23'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project, ['lead_actions' => ['offsite_conversion.custom.987654321']]);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(23.0, $this->leadsRecorded($project));
    }

    public function test_the_map_command_stores_the_mapping(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->artisan('meta:map', [
            '--project' => $project->id,
            '--lead' => ['offsite_conversion.custom.555'],
        ])->assertSuccessful();

        $this->assertSame(
            ['offsite_conversion.custom.555'],
            Integration::first()->settings['lead_actions'],
        );
    }

    public function test_the_actions_command_reports_what_meta_returns(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'actions' => [
                ['action_type' => 'offsite_conversion.custom.987654321', 'value' => '23'],
                ['action_type' => 'landing_page_view', 'value' => '140'],
            ],
        ]]])]);

        $project = Project::factory()->create();
        $this->connect($project);

        $this->artisan('meta:actions', ['--project' => $project->id])
            ->expectsOutputToContain('offsite_conversion.custom.987654321')
            ->expectsOutputToContain('ignored')
            ->assertSuccessful();
    }

    public function test_paginated_results_are_followed(): void
    {
        Http::fakeSequence()
            ->push([
                'data' => $this->insights([['action_type' => 'lead', 'value' => '5']])['data'],
                'paging' => ['next' => 'https://graph.facebook.com/v23.0/next-page'],
            ])
            ->push([
                'data' => [[
                    'ad_id' => '2',
                    'ad_name' => 'Second page ad',
                    'date_stop' => now()->subDay()->toDateString(),
                    'spend' => '10.00',
                    'impressions' => '500',
                    'clicks' => '20',
                    'actions' => [['action_type' => 'lead', 'value' => '4']],
                ]],
            ]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(9.0, $this->leadsRecorded($project));
        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'ad_spend', 'value' => 10]);
    }

    public function test_the_sync_reads_a_ninety_day_window_by_default(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([
            ['action_type' => 'lead', 'value' => '2'],
        ]))]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        // Ads that stopped weeks ago must still be fetched, otherwise they
        // vanish from the Ads screen despite having spent money.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'insights')) {
                return true;
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $range = json_decode($query['time_range'] ?? '{}', true);

            return isset($range['since'])
                && $range['since'] === now()->subDays(90)->toDateString();
        });
    }

    public function test_the_window_can_be_widened_per_project(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response($this->insights([]))]);

        $project = Project::factory()->create();
        $this->connect($project, ['insights_days' => 180]);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'insights')) {
                return true;
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $range = json_decode($query['time_range'] ?? '{}', true);

            return ($range['since'] ?? null) === now()->subDays(180)->toDateString();
        });
    }
}
