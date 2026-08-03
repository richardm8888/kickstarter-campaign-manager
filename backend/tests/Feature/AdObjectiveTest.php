<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mirrors a real account: some ads run Instant Form lead campaigns while
 * others are set to buy landing page views. The two cannot be judged by
 * the same yardstick.
 */
class AdObjectiveTest extends TestCase
{
    use RefreshDatabase;

    private function recordAd(
        Project $project,
        string $adId,
        string $name,
        ?string $optimizationGoal,
        float $spend,
        int $clicks,
        int $leads,
        int $pageViews,
    ): void {
        $recorder = app(MetricRecorder::class);

        $dimensions = [
            'ad_id' => $adId,
            'ad_name' => $name,
            'campaign_name' => 'Kickstarter Preview',
            'optimization_goal' => $optimizationGoal,
            'objective' => null,
        ];

        foreach ([
            'ad_spend' => $spend,
            'ad_clicks' => $clicks,
            'ad_leads' => $leads,
            'ad_impressions' => 2000,
            'ad_landing_page_views' => $pageViews,
            'ad_view_content' => 0,
        ] as $metric => $value) {
            $recorder->record($project, 'meta', $metric, $value, now()->subDay(), $dimensions);
        }
    }

    private function analyse(Project $project): array
    {
        return app(AdPerformanceAnalyser::class)->analyse($project);
    }

    public function test_a_lead_form_ad_is_judged_on_cost_per_signup(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, '1', 'Static Image', 'LEAD_GENERATION',
            spend: 2.16, clicks: 20, leads: 6, pageViews: 0);

        $ad = collect($this->analyse($project)['ads'])->firstWhere('ad_id', '1');

        $this->assertSame('signups', $ad['objective']);
        $this->assertSame(0.36, $ad['cpl']);
        $this->assertSame('scale', $ad['verdict']);
    }

    public function test_a_landing_page_view_ad_is_not_condemned_for_having_no_signups(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        // A lead ad converting well, alongside a traffic ad with no leads.
        $this->recordAd($project, '1', 'Static Image', 'LEAD_GENERATION',
            spend: 2.16, clicks: 20, leads: 6, pageViews: 0);
        $this->recordAd($project, '2', 'Footage', 'LANDING_PAGE_VIEWS',
            spend: 30.00, clicks: 60, leads: 0, pageViews: 43);

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('traffic', $ads['2']['objective']);
        $this->assertSame('fix', $ads['2']['verdict'], 'a traffic ad should not be dropped for lacking signups');
        $this->assertStringContainsString('Optimised for landing page views', $ads['2']['reason']);
        $this->assertStringContainsString('Leads or Sales campaign', $ads['2']['action']);
        $this->assertSame(0.70, $ads['2']['cost_per_page_view']);
    }

    public function test_the_report_summarises_spend_on_the_wrong_objective(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, '1', 'Static Image', 'LEAD_GENERATION',
            spend: 2.16, clicks: 20, leads: 6, pageViews: 0);
        $this->recordAd($project, '2', 'Footage', 'LANDING_PAGE_VIEWS',
            spend: 30.00, clicks: 60, leads: 0, pageViews: 43);
        $this->recordAd($project, '3', 'Static Website', 'LANDING_PAGE_VIEWS',
            spend: 25.00, clicks: 90, leads: 0, pageViews: 84);

        $report = $this->analyse($project);

        $this->assertSame(2, $report['traffic_objective_count']);
        $this->assertSame(55.0, $report['traffic_objective_spend']);
    }

    public function test_an_unknown_objective_falls_back_to_the_normal_rules(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, '1', 'Good', null, spend: 30, clicks: 60, leads: 30, pageViews: 50);
        $this->recordAd($project, '2', 'Dud', null, spend: 40, clicks: 40, leads: 0, pageViews: 30);

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('other', $ads['2']['objective']);
        $this->assertSame('drop', $ads['2']['verdict']);
    }
}
