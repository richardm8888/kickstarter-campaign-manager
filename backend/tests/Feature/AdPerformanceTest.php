<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Recommendations\AdPerformanceAnalyser;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /** Records a day of performance for one ad. */
    private function recordAd(
        Project $project,
        string $adId,
        float $spend,
        int $clicks,
        int $leads,
        int $impressions = 1000,
        int $daysAgo = 1,
    ): void {
        $recorder = app(MetricRecorder::class);
        $dimensions = ['ad_id' => $adId, 'ad_name' => "Ad {$adId}", 'campaign_name' => 'Pre-launch'];

        foreach ([
            'ad_spend' => $spend,
            'ad_clicks' => $clicks,
            'ad_leads' => $leads,
            'ad_impressions' => $impressions,
            'ad_view_content' => $clicks * 0.8,
        ] as $metric => $value) {
            $recorder->record($project, 'meta', $metric, $value, now()->subDays($daysAgo), $dimensions);
        }
    }

    private function analyse(Project $project): array
    {
        return app(AdPerformanceAnalyser::class)->analyse($project);
    }

    public function test_a_cheap_converting_ad_is_marked_to_scale(): void
    {
        // £45 average pledge; 2% of plain email leads back, so a lead is
        // worth about £0.90 and anything dearer than that loses money.
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'cheap', spend: 20, clicks: 100, leads: 50);   // £0.40 per lead
        $this->recordAd($project, 'dear', spend: 50, clicks: 100, leads: 10);    // £5.00 per lead

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('scale', $ads['cheap']['verdict']);
        $this->assertSame(0.4, $ads['cheap']['cpl']);
        $this->assertStringContainsString('Raise its budget', $ads['cheap']['action']);
    }

    public function test_an_ad_costing_more_than_a_subscriber_is_worth_is_dropped(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'expensive', spend: 100, clicks: 100, leads: 5); // £20 per lead
        $this->recordAd($project, 'fine', spend: 50, clicks: 100, leads: 40);

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('drop', $ads['expensive']['verdict']);
        $this->assertStringContainsString('loses money', $ads['expensive']['action']);
    }

    public function test_spending_with_no_signups_is_dropped(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'dud', spend: 80, clicks: 60, leads: 0);
        $this->recordAd($project, 'good', spend: 40, clicks: 80, leads: 30);

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('drop', $ads['dud']['verdict']);
        $this->assertStringContainsString('no signups', $ads['dud']['reason']);
    }

    public function test_a_barely_spent_ad_is_left_to_learn(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'new', spend: 4, clicks: 5, leads: 0);
        $this->recordAd($project, 'established', spend: 60, clicks: 100, leads: 40);

        $ads = collect($this->analyse($project)['ads'])->keyBy('ad_id');

        $this->assertSame('learning', $ads['new']['verdict']);
        $this->assertStringContainsString('too early', $ads['new']['reason']);
    }

    public function test_daily_rows_accumulate_across_the_window_but_not_across_repeat_syncs(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'a', spend: 20, clicks: 40, leads: 10, daysAgo: 1);
        $this->recordAd($project, 'a', spend: 20, clicks: 40, leads: 10, daysAgo: 1); // re-sync of same day
        $this->recordAd($project, 'a', spend: 30, clicks: 60, leads: 15, daysAgo: 2);

        $ad = collect($this->analyse($project)['ads'])->firstWhere('ad_id', 'a');

        $this->assertSame(50.0, $ad['spend']);  // 20 + 30, not 70
        $this->assertSame(25, $ad['leads']);
    }

    public function test_without_lead_events_it_judges_on_clicks_and_says_so(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);

        $this->recordAd($project, 'a', spend: 60, clicks: 100, leads: 0);
        $this->recordAd($project, 'b', spend: 60, clicks: 20, leads: 0);

        $result = $this->analyse($project);
        $ads = collect($result['ads'])->keyBy('ad_id');

        $this->assertFalse($result['has_lead_data']);
        $this->assertStringContainsString('Lead event', $ads['a']['action']);
        $this->assertSame('keep', $ads['a']['verdict']); // £0.60 per click
        $this->assertSame('fix', $ads['b']['verdict']);  // £3.00 per click
    }

    public function test_the_endpoint_returns_benchmarks_and_ordered_verdicts(): void
    {
        $project = Project::factory()->create(['average_pledge' => 4500]);
        Sanctum::actingAs($project->user);

        $this->recordAd($project, 'winner', spend: 20, clicks: 100, leads: 50);
        $this->recordAd($project, 'loser', spend: 60, clicks: 40, leads: 0);

        $response = $this->getJson("/api/projects/{$project->id}/ads")->assertOk();

        $response->assertJsonPath('benchmark.total_leads', 50)
            ->assertJsonPath('has_lead_data', true)
            ->assertJsonStructure(['benchmark' => ['account_cpl', 'affordable_cpl'], 'ads']);

        // Scale first, then drop: the two an operator must act on.
        $this->assertSame('scale', $response->json('ads.0.verdict'));
        $this->assertSame('drop', $response->json('ads.1.verdict'));
    }

    public function test_event_status_reports_what_is_arriving(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $before = $this->getJson("/api/projects/{$project->id}/ads/events")->assertOk();
        $this->assertFalse($before->json('events.1.detected'));

        $this->recordAd($project, 'a', spend: 20, clicks: 40, leads: 12);

        $after = $this->getJson("/api/projects/{$project->id}/ads/events")->assertOk();

        $events = collect($after->json('events'))->keyBy('event');
        $this->assertTrue($events['Lead']['detected']);
        $this->assertSame(12, $events['Lead']['total']);
        $this->assertTrue($events['ViewContent']['detected']);
    }

    public function test_ads_are_scoped_to_the_owner(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->getJson("/api/projects/{$project->id}/ads")->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/ads/events")->assertForbidden();
    }
}
