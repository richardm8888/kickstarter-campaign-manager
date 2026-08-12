<?php

namespace Tests\Feature;

use App\Actions\RunIntegrationSync;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Where an ad's running state comes from.
 *
 * Insights reports spend for every ad that ran in the window. The ads
 * edge, which is where status lives, hides archived and deleted ones
 * unless asked — so the two lists disagree exactly about the ads a
 * creator has finished with.
 */
class MetaAdStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        $project = Project::factory()->create();

        $project->integrations()->create([
            'provider' => 'meta',
            'status' => 'connected',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
        ]);

        return $project;
    }

    private function fakeMeta(array $insightRows, ?array $adRows): void
    {
        Http::fake([
            '*/insights*' => Http::response(['data' => $insightRows]),
            '*/ads*' => $adRows === null
                ? Http::response(['error' => ['message' => 'nope']], 400)
                : Http::response(['data' => $adRows]),
        ]);
    }

    private function statusOf(Project $project, string $adId): ?string
    {
        return $project->metricSnapshots()
            ->where('metric', 'ad_spend')
            ->get()
            ->first(fn ($s) => ($s->dimensions['ad_id'] ?? null) === $adId)
            ?->dimensions['ad_status'] ?? null;
    }

    public function test_an_ad_missing_from_the_catalogue_is_not_running(): void
    {
        // Meta will not list a deleted ad however we ask, but it keeps
        // reporting its spend — so absence from a catalogue we could read
        // is itself the answer.
        $this->fakeMeta(
            [['ad_id' => '1', 'ad_name' => 'Old', 'spend' => '8.20', 'date_stop' => now()->toDateString()]],
            [['id' => '99', 'effective_status' => 'ACTIVE', 'creative' => []]],
        );

        $project = $this->project();
        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame('DELETED', $this->statusOf($project, '1'));
    }

    public function test_a_failed_catalogue_leaves_ads_running(): void
    {
        // Unknown has to keep meaning running: a broken request must not
        // empty the page and make every ad look switched off.
        $this->fakeMeta(
            [['ad_id' => '1', 'ad_name' => 'Live', 'spend' => '8.20', 'date_stop' => now()->toDateString()]],
            null,
        );

        $project = $this->project();
        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame('ACTIVE', $this->statusOf($project, '1'));
    }

    public function test_it_asks_for_archived_ads_explicitly(): void
    {
        $this->fakeMeta([], []);

        app(RunIntegrationSync::class)->handle($this->project(), 'meta');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/ads')) {
                return true;
            }

            // Without this filter the edge silently omits the very ads
            // whose status we most need.
            return str_contains(urldecode($request->url()), 'ARCHIVED')
                && str_contains(urldecode($request->url()), 'effective_status');
        });
    }

    public function test_a_listed_ad_keeps_the_status_meta_reports(): void
    {
        $this->fakeMeta(
            [['ad_id' => '1', 'ad_name' => 'Paused', 'spend' => '8.20', 'date_stop' => now()->toDateString()]],
            [['id' => '1', 'effective_status' => 'CAMPAIGN_PAUSED', 'creative' => []]],
        );

        $project = $this->project();
        app(RunIntegrationSync::class)->handle($project, 'meta');

        $this->assertSame('CAMPAIGN_PAUSED', $this->statusOf($project, '1'));
    }
}
