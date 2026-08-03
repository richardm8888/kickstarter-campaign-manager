<?php

namespace Tests\Feature;

use App\Jobs\SyncIntegration;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_when_nothing_is_connected(): void
    {
        $this->artisan('integrations:sync')
            ->expectsOutputToContain('No connected integrations matched.')
            ->assertSuccessful();
    }

    public function test_it_reports_a_successful_sync(): void
    {
        Http::fake(['connect.mailerlite.com/*' => Http::response(['total' => 143])]);

        $project = Project::factory()->create(['name' => 'Totally Football']);
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'key'],
        ]);

        $this->artisan('integrations:sync')
            ->expectsOutputToContain('mailerlite')
            ->assertSuccessful();

        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'email_subscribers']);
    }

    public function test_it_exits_nonzero_and_shows_the_error_when_a_sync_fails(): void
    {
        Http::fake(['connect.mailerlite.com/*' => Http::response(['message' => 'Unauthenticated'], 401)]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'wrong'],
        ]);

        $this->artisan('integrations:sync')->assertFailed();

        $this->assertSame(Integration::STATUS_ERROR, Integration::first()->status);
    }

    public function test_it_can_filter_by_provider(): void
    {
        Queue::fake();

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'mailerlite']);
        Integration::factory()->for($project)->create(['provider' => 'stripe']);

        $this->artisan('integrations:sync', ['--provider' => 'stripe', '--queue' => true])
            ->assertSuccessful();

        Queue::assertPushed(SyncIntegration::class, 1);
        Queue::assertPushed(fn (SyncIntegration $job) => $job->provider === 'stripe');
    }
}
