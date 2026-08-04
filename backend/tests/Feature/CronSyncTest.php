<?php

namespace Tests\Feature;

use App\Jobs\ImportLeadsForAllProjects;
use App\Jobs\SyncAllIntegrations;
use App\Jobs\SyncIntegration;
use App\Jobs\SyncKickstarterFollowers;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CronSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_run_sync_rejects_requests_without_the_secret(): void
    {
        config(['app.cron_secret' => 'top-secret']);

        $this->postJson('/api/internal/run-sync')->assertForbidden();
        $this->postJson('/api/internal/run-sync', [], ['X-Cron-Secret' => 'wrong'])->assertForbidden();
    }

    public function test_run_sync_is_disabled_when_no_secret_is_configured(): void
    {
        config(['app.cron_secret' => null]);

        $this->postJson('/api/internal/run-sync', [], ['X-Cron-Secret' => ''])->assertForbidden();
    }

    public function test_run_sync_covers_contacts_as_well_as_metrics(): void
    {
        config(['app.cron_secret' => 'top-secret']);
        Queue::fake();

        $this->postJson('/api/internal/run-sync', [], ['X-Cron-Secret' => 'top-secret'])
            ->assertOk();

        // Metrics alone would leave a scheduler-less host never pulling
        // Instant Form leads, so no welcome email would ever be sent.
        Queue::assertPushed(SyncAllIntegrations::class);
        Queue::assertPushed(ImportLeadsForAllProjects::class);
        Queue::assertPushed(SyncKickstarterFollowers::class);
    }

    public function test_the_sync_job_fans_out_to_connected_integrations_only(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'mailerlite']);
        Integration::factory()->for($project)->disconnected()->create(['provider' => 'stripe']);

        (new SyncAllIntegrations)->handle();

        Queue::assertPushed(SyncIntegration::class, 1);
    }
}
