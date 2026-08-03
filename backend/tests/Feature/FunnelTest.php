<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Recommendations\AdType;
use App\Services\Analytics\MetricRecorder;
use App\Services\Funnel\FunnelBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FunnelTest extends TestCase
{
    use RefreshDatabase;

    private function ad(Project $project, AdType $type, string $adId, array $metrics): void
    {
        $recorder = app(MetricRecorder::class);

        foreach ($metrics as $metric => $value) {
            $recorder->record($project, 'meta', $metric, $value, now()->subDay(), [
                'ad_id' => $adId,
                'ad_type' => $type->value,
            ]);
        }
    }

    private function steps(Project $project): array
    {
        return collect(app(FunnelBuilder::class)->build($project)['steps'])
            ->keyBy('key')
            ->all();
    }

    public function test_it_starts_with_impressions_and_the_spend_behind_them(): void
    {
        $project = Project::factory()->create();
        $this->ad($project, AdType::LandingPage, '1', [
            'ad_impressions' => 40000,
            'ad_spend' => 120.50,
            'ad_clicks' => 800,
        ]);

        $steps = $this->steps($project);

        $this->assertSame('impressions', array_key_first($steps));
        $this->assertSame(40000, $steps['impressions']['value']);
        $this->assertSame(12050, $steps['impressions']['spend']);
    }

    public function test_clicks_branch_into_landing_page_views_and_form_views(): void
    {
        $project = Project::factory()->create();

        $this->ad($project, AdType::LandingPage, '1', [
            'ad_clicks' => 300,
            'ad_landing_page_views' => 250,
        ]);
        $this->ad($project, AdType::Kickstarter, '2', [
            'ad_clicks' => 200,
            'ad_landing_page_views' => 180,
        ]);
        $this->ad($project, AdType::InstantForm, '3', [
            'ad_clicks' => 100,
            'ad_form_views' => 90,
        ]);

        $branches = collect($this->steps($project)['reached']['branches'])->keyBy('key');

        // Both web destinations count as a landing page view.
        $this->assertSame(430, $branches['landing_page_views']['value']);
        $this->assertSame(90, $branches['form_views']['value']);
        $this->assertEqualsWithDelta(71.7, $branches['landing_page_views']['conversion'], 0.1);
        $this->assertSame(15.0, $branches['form_views']['conversion']);
    }

    public function test_form_views_fall_back_to_clicks_when_meta_reports_no_form_opens(): void
    {
        $project = Project::factory()->create();

        // Not every account emits lead_form_open, but a click on a form ad
        // is the open.
        $this->ad($project, AdType::InstantForm, '3', ['ad_clicks' => 120]);

        $branches = collect($this->steps($project)['reached']['branches'])->keyBy('key');

        $this->assertSame(120, $branches['form_views']['value']);
    }

    public function test_signups_combine_owned_contacts_and_kickstarter_follows(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        $recorder->record($project, 'mailerlite', 'email_subscribers', 67);
        $this->ad($project, AdType::Kickstarter, '2', ['ad_follows' => 14, 'ad_clicks' => 200]);

        $steps = $this->steps($project);

        $this->assertSame(81, $steps['signups']['value']);
        $this->assertStringContainsString('from ads', (string) $steps['signups']['note']);
    }

    public function test_signup_conversion_is_measured_against_the_ad_window_not_the_standing_list(): void
    {
        $project = Project::factory()->create();
        $recorder = app(MetricRecorder::class);

        // A big list built long ago must not read as 6,700% conversion.
        $recorder->record($project, 'mailerlite', 'email_subscribers', 6700);
        $this->ad($project, AdType::LandingPage, '1', [
            'ad_clicks' => 1000,
            'ad_landing_page_views' => 800,
            'ad_leads' => 40,
        ]);

        $signups = $this->steps($project)['signups'];

        $this->assertSame(5.0, $signups['conversion'], '40 of 800 arrivals signed up');
        $this->assertSame('total', $signups['basis']);
    }

    public function test_a_project_with_no_ads_yet_still_returns_every_step(): void
    {
        $steps = $this->steps(Project::factory()->create());

        $this->assertSame(
            ['impressions', 'ad_clicks', 'reached', 'signups', 'vip_upgrade', 'backers'],
            array_keys($steps),
        );

        foreach ($steps as $step) {
            $this->assertSame(0, $step['value']);
            $this->assertNull($step['conversion']);
        }
    }

    public function test_repeated_syncs_of_the_same_day_do_not_multiply_the_funnel(): void
    {
        $project = Project::factory()->create();

        // Meta restates the day on every sync.
        foreach (range(1, 3) as $ignored) {
            $this->ad($project, AdType::LandingPage, '1', [
                'ad_impressions' => 40000,
                'ad_clicks' => 800,
            ]);
        }

        $steps = $this->steps($project);

        $this->assertSame(40000, $steps['impressions']['value']);
        $this->assertSame(800, $steps['ad_clicks']['value']);
    }
}
