<?php

namespace Tests\Feature;

use App\Jobs\SyncIntegration;
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

    public function test_run_sync_fans_out_syncs_for_connected_integrations(): void
    {
        config(['app.cron_secret' => 'top-secret']);
        Queue::fake();

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'mailerlite']);
        Integration::factory()->for($project)->disconnected()->create(['provider' => 'stripe']);

        $this->postJson('/api/internal/run-sync', [], ['X-Cron-Secret' => 'top-secret'])
            ->assertOk();

        Queue::assertPushed(SyncIntegration::class, 1);
    }
}
