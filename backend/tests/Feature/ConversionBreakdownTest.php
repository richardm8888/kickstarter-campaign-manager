<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sessions against signups, split by referrer and by region.
 */
class ConversionBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $metric, float $value, array $dimensions, int $daysAgo = 1): void
    {
        app(MetricRecorder::class)->record(
            $project, 'ga4', $metric, $value, now()->subDays($daysAgo), $dimensions,
        );
    }

    private function breakdown(Project $project): array
    {
        Sanctum::actingAs($project->user);

        return $this->getJson("/api/projects/{$project->id}/analytics?category=conversion")
            ->assertOk()
            ->json('breakdown');
    }

    public function test_it_splits_conversion_by_referrer(): void
    {
        $project = Project::factory()->create();

        $this->record($project, 'sessions_by_source', 400, ['source' => 'facebook']);
        $this->record($project, 'leads_by_source', 8, ['source' => 'facebook']);
        $this->record($project, 'sessions_by_source', 100, ['source' => 'Direct']);
        $this->record($project, 'leads_by_source', 25, ['source' => 'Direct']);

        $sources = collect($this->breakdown($project)['by_source'])->keyBy('key');

        // Busiest first, but the smaller source converts eight times better
        // — the whole reason for splitting it.
        $this->assertEquals(2.0, $sources['facebook']['conversion']);
        $this->assertEquals(25.0, $sources['Direct']['conversion']);
    }

    public function test_a_referrer_with_too_little_traffic_reports_no_rate(): void
    {
        // Zero and "cannot say" look identical as 0%, and they are not.
        $project = Project::factory()->create();

        $this->record($project, 'sessions_by_source', 4, ['source' => 'reddit']);
        $this->record($project, 'leads_by_source', 0, ['source' => 'reddit']);

        $reddit = collect($this->breakdown($project)['by_source'])->firstWhere('key', 'reddit');

        $this->assertNull($reddit['conversion']);
        $this->assertSame(4, $reddit['sessions']);
    }

    public function test_every_region_appears_even_with_no_traffic(): void
    {
        // A missing row reads as "no data"; a zero reads as "nobody", and
        // those lead to different decisions about where to advertise.
        $project = Project::factory()->create();

        $this->record($project, 'sessions_by_region', 300, ['region' => 'uk']);
        $this->record($project, 'leads_by_region', 30, ['region' => 'uk']);

        $regions = collect($this->breakdown($project)['by_region']);

        $this->assertSame(['uk', 'eu', 'international'], $regions->pluck('key')->all());
        $this->assertEquals(10.0, $regions->firstWhere('key', 'uk')['conversion']);
        $this->assertSame(0, $regions->firstWhere('key', 'eu')['sessions']);
    }

    public function test_repeat_syncs_do_not_multiply_a_segment(): void
    {
        // The provider restates the same day every hour. Summing those
        // would report a day's traffic as many times as the sync ran.
        $project = Project::factory()->create();

        foreach (range(1, 3) as $ignored) {
            $this->record($project, 'sessions_by_region', 300, ['region' => 'uk']);
            $this->record($project, 'leads_by_region', 30, ['region' => 'uk']);
        }

        $uk = collect($this->breakdown($project)['by_region'])->firstWhere('key', 'uk');

        $this->assertSame(300, $uk['sessions']);
        $this->assertSame(30, $uk['leads']);
    }

    public function test_the_long_tail_of_referrers_is_folded_away(): void
    {
        $project = Project::factory()->create();

        foreach (range(1, 20) as $i) {
            $this->record($project, 'sessions_by_source', 100 - $i, ['source' => "source-{$i}"]);
            $this->record($project, 'leads_by_source', 1, ['source' => "source-{$i}"]);
        }

        $sources = $this->breakdown($project)['by_source'];

        $this->assertCount(8, $sources);
        $this->assertSame('source-1', $sources[0]['key'], 'busiest first');
    }

    public function test_the_breakdown_is_only_built_for_the_conversion_view(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->assertNull(
            $this->getJson("/api/projects/{$project->id}/analytics?category=traffic")->json('breakdown'),
        );
    }

    public function test_ga4_records_signups_sources_and_regions(): void
    {
        // Two batched calls: totals then breakdowns. Sessions and signups
        // arrive as separate reports because a report filtered to lead
        // events would count only sessions that already converted.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/*' => Http::sequence()
                ->push(['reports' => [
                    ['rows' => []],
                    ['rows' => [[
                        'dimensionValues' => [['value' => '20260801'], ['value' => 'generate_lead']],
                        'metricValues' => [['value' => '17']],
                    ]]],
                ]])
                ->push(['reports' => [
                    // sessions by source
                    ['rows' => [[
                        'dimensionValues' => [['value' => '20260801'], ['value' => 'facebook']],
                        'metricValues' => [['value' => '240']],
                    ]]],
                    // signups by source
                    ['rows' => [[
                        'dimensionValues' => [['value' => '20260801'], ['value' => 'facebook']],
                        'metricValues' => [['value' => '12']],
                    ]]],
                    // sessions by country
                    ['rows' => [
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'GB']], 'metricValues' => [['value' => '200']]],
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'DE']], 'metricValues' => [['value' => '50']]],
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'FR']], 'metricValues' => [['value' => '30']]],
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'US']], 'metricValues' => [['value' => '80']]],
                    ]],
                    // signups by country
                    ['rows' => [
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'GB']], 'metricValues' => [['value' => '20']]],
                    ]],
                ]])
                // Sessions on the Kickstarter page itself, which arrive
                // only when its Google Analytics ID points here.
                ->push(['reports' => [
                    ['rows' => [
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'mailerlite']], 'metricValues' => [['value' => '31']]],
                        ['dimensionValues' => [['value' => '20260801'], ['value' => 'fb']], 'metricValues' => [['value' => '64']]],
                    ]],
                ]]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'ga4',
            'credentials' => [
                'property_id' => '1',
                'service_account_json' => $this->serviceAccountKey(),
            ],
        ]);

        app(\App\Integrations\IntegrationManager::class)->for($project, 'ga4')->sync();

        $this->assertDatabaseHas('metric_snapshots', [
            'source' => 'ga4', 'metric' => 'site_leads', 'value' => 17,
        ]);

        $regions = $project->metricSnapshots()
            ->where('metric', 'sessions_by_region')->get()
            ->mapWithKeys(fn ($s) => [$s->dimensions['region'] => (int) $s->value]);

        $this->assertSame(200, $regions['uk']);
        // Germany and France add up rather than overwriting each other.
        $this->assertSame(80, $regions['eu']);
        $this->assertSame(80, $regions['international']);

        $ukLeads = $project->metricSnapshots()
            ->where('metric', 'leads_by_region')->get()
            ->first(fn ($s) => $s->dimensions['region'] === 'uk');

        $this->assertSame(20.0, (float) $ukLeads->value);

        $fromEmail = $project->metricSnapshots()
            ->where('metric', 'ks_page_sessions_by_source')->get()
            ->first(fn ($s) => $s->dimensions['source'] === 'mailerlite');

        $this->assertSame(31.0, (float) $fromEmail->value);
    }

    public function test_kickstarter_page_visits_are_read_by_hostname(): void
    {
        // The tag on a Kickstarter page reports into the same property as
        // the creator's own site, so hostname is the only thing separating
        // them.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/*' => Http::response(['reports' => [
                ['rows' => []], ['rows' => []], ['rows' => []], ['rows' => []],
            ]]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'ga4',
            'credentials' => [
                'property_id' => '1',
                'service_account_json' => $this->serviceAccountKey(),
            ],
        ]);

        app(\App\Integrations\IntegrationManager::class)->for($project, 'ga4')->sync();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'analyticsdata')) {
                return true;
            }

            foreach ($request->data()['requests'] ?? [] as $report) {
                if (($report['dimensionFilter']['filter']['fieldName'] ?? null) === 'hostName') {
                    return str_contains(
                        $report['dimensionFilter']['filter']['stringFilter']['value'] ?? '',
                        'kickstarter.com',
                    );
                }
            }

            return true;
        });
    }

    public function test_sessions_are_not_scoped_to_visitors_who_converted(): void
    {
        // The bug this shape exists to prevent: asking for sessions in a
        // report filtered to lead events returns only sessions that
        // already converted, and every rate comes out near 100%.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/*' => Http::response(['reports' => [['rows' => []], ['rows' => []], ['rows' => []], ['rows' => []]]]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'ga4',
            'credentials' => [
                'property_id' => '1',
                'service_account_json' => $this->serviceAccountKey(),
            ],
        ]);

        app(\App\Integrations\IntegrationManager::class)->for($project, 'ga4')->sync();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'analyticsdata')) {
                return true;
            }

            foreach ($request->data()['requests'] ?? [] as $report) {
                $wantsSessions = collect($report['metrics'] ?? [])->contains('name', 'sessions');

                if ($wantsSessions && isset($report['dimensionFilter'])) {
                    return false;
                }
            }

            return true;
        });
    }

    private function serviceAccountKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return json_encode(['client_email' => 'svc@example.com', 'private_key' => $pem]);
    }
}
