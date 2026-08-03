<?php

namespace Tests\Feature;

use App\Actions\RunIntegrationSync;
use App\Models\Integration;
use App\Models\MetricSnapshot;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conversion types are classified the same way for everyone: the platform
 * prescribes the campaign setup rather than adapting to whatever exists.
 */
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

    private function recorded(Project $project, string $metric): float
    {
        return (float) MetricSnapshot::where('project_id', $project->id)
            ->where('metric', $metric)
            ->sum('value');
    }

    /** Creatives that make an ad each of the three recommended types. */
    private function creative(string $type): array
    {
        return match ($type) {
            'instant_form' => ['object_story_spec' => ['link_data' => ['lead_gen_form_id' => '999']]],
            'kickstarter' => ['object_story_spec' => ['link_data' => ['link' => 'https://www.kickstarter.com/projects/x/y']]],
            'landing_page' => ['object_story_spec' => ['link_data' => ['link' => 'https://totallyfootballgame.co.uk']]],
            default => [],
        };
    }

    private function sync(array $actions, string $adType = 'instant_form', array $settings = []): Project
    {
        Http::fake([
            '*/insights*' => Http::response($this->insights($actions)),
            '*/ads*' => Http::response(['data' => [
                ['id' => '1', 'creative' => $this->creative($adType)],
                ['id' => '2', 'creative' => $this->creative($adType)],
            ]]),
        ]);

        $project = Project::factory()->create();
        $this->connect($project, $settings);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        return $project;
    }

    public function test_instant_form_submissions_are_counted_as_signups(): void
    {
        $project = $this->sync([['action_type' => 'leadgen_grouped', 'value' => '6']]);

        $this->assertSame(6.0, $this->recorded($project, 'ad_leads'));
        $this->assertSame(0.0, $this->recorded($project, 'ad_follows'));
    }

    public function test_a_pixel_lead_on_a_kickstarter_ad_is_a_follow(): void
    {
        $project = $this->sync(
            [['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '14']],
            'kickstarter',
        );

        $this->assertSame(14.0, $this->recorded($project, 'ad_follows'));
        $this->assertSame(0.0, $this->recorded($project, 'ad_leads'), 'a follow is not an owned contact');
    }

    public function test_a_pixel_lead_on_a_landing_page_ad_is_an_owned_signup(): void
    {
        // Same Meta event, different destination, different meaning.
        $project = $this->sync(
            [['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '11']],
            'landing_page',
        );

        $this->assertSame(11.0, $this->recorded($project, 'ad_leads'));
        $this->assertSame(0.0, $this->recorded($project, 'ad_follows'));
    }

    public function test_the_two_are_separated_when_both_arrive(): void
    {
        // Meta also returns an umbrella "lead" totalling both; counting it
        // would double up, so it is ignored entirely.
        $project = $this->sync([
            ['action_type' => 'lead', 'value' => '20'],
            ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '14'],
            ['action_type' => 'leadgen_grouped', 'value' => '6'],
        ], 'kickstarter');

        $this->assertSame(6.0, $this->recorded($project, 'ad_leads'));
        $this->assertSame(14.0, $this->recorded($project, 'ad_follows'));
    }

    public function test_the_same_form_lead_reported_twice_is_not_double_counted(): void
    {
        $project = $this->sync([
            ['action_type' => 'leadgen_grouped', 'value' => '12'],
            ['action_type' => 'onsite_conversion.lead_grouped', 'value' => '12'],
        ]);

        $this->assertSame(12.0, $this->recorded($project, 'ad_leads'));
    }

    public function test_a_zero_does_not_hide_a_real_count(): void
    {
        $project = $this->sync([
            ['action_type' => 'onsite_web_lead', 'value' => '0'],
            ['action_type' => 'leadgen_grouped', 'value' => '9'],
        ]);

        $this->assertSame(9.0, $this->recorded($project, 'ad_leads'));
    }

    public function test_landing_page_views_stay_separate_from_view_content(): void
    {
        $project = $this->sync([
            ['action_type' => 'landing_page_view', 'value' => '123'],
            ['action_type' => 'view_content', 'value' => '7'],
        ]);

        $this->assertSame(123.0, $this->recorded($project, 'ad_landing_page_views'));
        $this->assertSame(7.0, $this->recorded($project, 'ad_view_content'));
    }

    public function test_the_classification_cannot_be_overridden_by_settings(): void
    {
        // Stale configuration from an earlier build must not change results.
        $project = $this->sync(
            [['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '14']],
            'kickstarter',
            ['lead_actions' => ['offsite_conversion.fb_pixel_lead'], 'follow_actions' => []],
        );

        $this->assertSame(14.0, $this->recorded($project, 'ad_follows'));
        $this->assertSame(0.0, $this->recorded($project, 'ad_leads'));
    }

    public function test_paginated_results_are_followed(): void
    {
        Http::fakeSequence()
            ->push([
                'data' => $this->insights([['action_type' => 'leadgen_grouped', 'value' => '5']])['data'],
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
                    'actions' => [['action_type' => 'leadgen_grouped', 'value' => '4']],
                ]],
            ])
            // Third call is the creative lookup that classifies each ad.
            ->push(['data' => []]);

        $project = Project::factory()->create();
        $this->connect($project);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame(9.0, $this->recorded($project, 'ad_leads'));
        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'ad_spend', 'value' => 10]);
    }

    public function test_the_sync_reads_a_ninety_day_window_including_today(): void
    {
        $this->sync([['action_type' => 'leadgen_grouped', 'value' => '2']]);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'insights')) {
                return true;
            }

            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $range = json_decode($query['time_range'] ?? '{}', true);

            // "until" must be today: Meta's last_Nd presets stop at
            // yesterday, which hid conversions from the last 24 hours.
            return ($range['since'] ?? null) === now()->subDays(90)->toDateString()
                && ($range['until'] ?? null) === now()->toDateString();
        });
    }
}
