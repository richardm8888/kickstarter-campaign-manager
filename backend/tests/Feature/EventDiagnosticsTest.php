<?php

namespace Tests\Feature;

use App\Actions\RunIntegrationSync;
use App\Models\Integration;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Subscriber;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EventDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $metric, float $value): void
    {
        app(MetricRecorder::class)->record(
            $project, 'meta', $metric, $value, now()->subDay(),
            ['ad_id' => '1', 'ad_name' => 'Ad'],
        );
    }

    private function diagnostics(Project $project): array
    {
        Sanctum::actingAs($project->user);

        return $this->getJson("/api/projects/{$project->id}/ads/events")
            ->assertOk()
            ->json('diagnostics');
    }

    public function test_landing_page_views_are_not_counted_as_view_content(): void
    {
        // Real shape from a live account: many landing page views, few
        // ViewContent events because the tag barely fires.
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'ad_id' => '1',
            'ad_name' => 'Video demo',
            'date_stop' => now()->subDay()->toDateString(),
            'spend' => '40.00',
            'impressions' => '5000',
            'clicks' => '144',
            'actions' => [
                ['action_type' => 'landing_page_view', 'value' => '123'],
                ['action_type' => 'view_content', 'value' => '7'],
                ['action_type' => 'offsite_conversion.fb_pixel_view_content', 'value' => '7'],
                ['action_type' => 'leadgen_grouped', 'value' => '2'],
            ],
        ]]])]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
        ]);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $value = fn (string $metric) => (float) MetricSnapshot::where('metric', $metric)->sum('value');

        $this->assertSame(7.0, $value('ad_view_content'), 'ViewContent must not absorb landing page views');
        $this->assertSame(123.0, $value('ad_landing_page_views'));
        $this->assertSame(2.0, $value('ad_leads'));
    }

    public function test_it_flags_a_view_content_tag_that_barely_fires(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ad_landing_page_views', 123);
        $this->record($project, 'ad_view_content', 7);

        $findings = collect($this->diagnostics($project));

        // 7 of 123 visits = 6%.
        $this->assertStringContainsString('6% of visits', $findings->first()['title']);
        $this->assertStringContainsString('123 landing page views', $findings->first()['body']);
        $this->assertStringContainsString('missing from the page', $findings->first()['body']);
    }

    public function test_it_flags_clicks_that_never_reach_the_page(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ad_clicks', 200);
        $this->record($project, 'ad_landing_page_views', 100);
        $this->record($project, 'ad_view_content', 95);

        $titles = collect($this->diagnostics($project))->pluck('title')->implode(' ');

        $this->assertStringContainsString('never load your page', $titles);
    }

    public function test_it_explains_when_meta_sees_fewer_signups_than_we_recorded(): void
    {
        $project = Project::factory()->create();

        Subscriber::factory()->for($project)->count(20)->create();
        $this->record($project, 'ad_leads', 2);

        $findings = collect($this->diagnostics($project));

        $signupFinding = $findings->firstWhere('title', 'Meta sees fewer signups than you actually got');
        $this->assertNotNull($signupFinding);
        $this->assertStringContainsString('ad blockers', $signupFinding['body']);
    }

    public function test_a_healthy_setup_raises_nothing(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'ad_clicks', 130);
        $this->record($project, 'ad_landing_page_views', 120);
        $this->record($project, 'ad_view_content', 118);

        $this->assertSame([], $this->diagnostics($project));
    }
}
