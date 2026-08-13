<?php

namespace Tests\Feature;

use App\Integrations\Providers\MailerLiteIntegration;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\FollowerLift;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Campaigns sent before this code existed still get measured.
 *
 * MailerLite's campaigns endpoint returns every sent campaign, not the
 * ones since we last asked, and the sync writes each row against the
 * date the campaign actually went out rather than the date it was read.
 * So the first sync after deploying fills in the history — nothing needs
 * a migration or a backfill script.
 *
 * This is pinned down because it is easy to assume the opposite, and I
 * did: a claim that the past was unrecoverable would have had someone
 * accept a blank table that was going to fill itself in within the hour.
 */
class CampaignSendBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function connect(Project $project): void
    {
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => [],
        ]);
    }

    /** Campaigns that went out well before today. */
    private function fakeMailerLite(array $campaigns): void
    {
        Http::fake([
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => $campaigns]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => [], 'total' => 120]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => []]),
        ]);
    }

    private function campaign(string $id, string $name, int $daysAgo, int $sent): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'finished_at' => now()->subDays($daysAgo)->toDateTimeString(),
            'emails' => [['subject' => "{$name} — inside"]],
            'stats' => ['sent' => $sent, 'opens_count' => 40, 'clicks_count' => 9, 'unsubscribes_count' => 1],
        ];
    }

    public function test_one_sync_recovers_campaigns_sent_long_before_it_ran(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->fakeMailerLite([
            $this->campaign('c1', 'Prototype reveal', 28, 210),
            $this->campaign('c2', 'Meet the designers', 17, 240),
            $this->campaign('c3', 'One month to go', 6, 260),
        ]);

        app(MailerLiteIntegration::class, ["project" => $project])->sync();

        $sends = $project->metricSnapshots()
            ->where('metric', 'email_campaign_sent')
            ->orderBy('recorded_at')
            ->get();

        $this->assertCount(3, $sends);

        // Dated when they were sent, not when they were read.
        $this->assertSame(
            now()->subDays(28)->toDateString(),
            $sends[0]->recorded_at->toDateString(),
        );
        $this->assertSame('Prototype reveal', $sends[0]->dimensions['campaign_name']);
        $this->assertSame(210.0, (float) $sends[0]->value);
    }

    public function test_the_recovered_history_is_immediately_measurable(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        // Follower history already exists — it is recorded hourly and has
        // been all along. It is only the sends that were missing.
        $recorder = app(MetricRecorder::class);
        $value = 5.0;

        for ($back = 45; $back >= 0; $back--) {
            $value += 0.6 + ($back === 17 ? 9 : 0);
            $recorder->record($project, 'kickstarter', 'ks_followers', round($value), now()->subDays($back)->toDateString());
        }

        $this->fakeMailerLite([$this->campaign('c2', 'Meet the designers', 17, 240)]);

        app(MailerLiteIntegration::class, ["project" => $project])->sync();

        $send = app(FollowerLift::class)->build($project, 90)['sends'][0];

        $this->assertSame('measured', $send['status']);
        $this->assertSame('Meet the designers', $send['name']);
        $this->assertGreaterThan(5.0, $send['lift']);
    }

    public function test_syncing_again_does_not_duplicate_a_send(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        // Followers too, or the read declines to report anything at
        // all and this would pass without testing the deduplication.
        $recorder = app(MetricRecorder::class);
        $value = 5.0;

        for ($back = 45; $back >= 0; $back--) {
            $value += 0.6 + ($back === 28 ? 9 : 0);
            $recorder->record($project, 'kickstarter', 'ks_followers', round($value), now()->subDays($back)->toDateString());
        }

        $this->fakeMailerLite([$this->campaign('c1', 'Prototype reveal', 28, 210)]);

        $integration = app(MailerLiteIntegration::class, ["project" => $project]);
        $integration->sync();
        $integration->sync();
        $integration->sync();

        // Three snapshot rows, because the store is append-only — but one
        // campaign, because the read keys on its id.
        $this->assertSame(
            3,
            $project->metricSnapshots()->where('metric', 'email_campaign_sent')->count(),
        );

        $this->assertCount(1, app(FollowerLift::class)->build($project, 90)['sends']);
    }
}
