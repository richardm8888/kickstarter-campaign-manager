<?php

namespace Tests\Feature;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_wizard_lists_all_supported_providers_with_status(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/integrations");

        $response->assertOk();
        $providers = collect($response->json('integrations'))->pluck('provider');

        $this->assertEqualsCanonicalizing(['meta', 'ga4', 'mailerlite', 'stripe'], $providers->all());
        $this->assertSame('disconnected', $response->json('integrations.0.status'));
    }

    public function test_connecting_an_integration_stores_credentials_and_queues_a_sync(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/integrations/mailerlite/connect", [
            'credentials' => ['api_key' => 'ml-secret'],
        ])->assertOk()->assertJsonPath('integration.status', 'connected');

        $record = Integration::firstWhere(['project_id' => $project->id, 'provider' => 'mailerlite']);
        $this->assertSame(['api_key' => 'ml-secret'], $record->credentials);

        Queue::assertPushed(SyncIntegration::class);
    }

    public function test_connecting_with_missing_credentials_fails_validation(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/integrations/meta/connect", [
            'credentials' => ['access_token' => 'tok'],
        ])->assertUnprocessable();
    }

    public function test_an_unknown_provider_is_rejected(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/integrations/hubspot/connect", [
            'credentials' => ['api_key' => 'x'],
        ])->assertUnprocessable();
    }

    public function test_disconnecting_clears_credentials(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'stripe']);
        Sanctum::actingAs($project->user);

        $this->deleteJson("/api/projects/{$project->id}/integrations/stripe")
            ->assertOk()
            ->assertJsonPath('integration.status', 'disconnected');

        $this->assertNull(Integration::firstWhere('provider', 'stripe')->credentials);
    }

    public function test_syncing_mailerlite_records_append_only_metrics(): void
    {
        Http::fake([
            'connect.mailerlite.com/*' => Http::response(['total' => 143]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-secret'],
        ]);

        (new SyncIntegration($project, 'mailerlite'))->handle(app(\App\Actions\RunIntegrationSync::class));

        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'source' => 'mailerlite',
            'metric' => 'email_subscribers',
        ]);
        $this->assertNotNull(Integration::firstWhere('provider', 'mailerlite')->last_synced_at);
    }
}
