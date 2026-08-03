<?php

namespace Tests\Feature;

use App\Actions\ImportMetaLeads;
use App\Actions\RunIntegrationSync;
use App\Models\Integration;
use App\Models\MetricSnapshot;
use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaLeadImportTest extends TestCase
{
    use RefreshDatabase;

    private function connectMeta(Project $project, array $settings = []): void
    {
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
            'settings' => $settings,
        ]);
    }

    private function fakeLeads(array $leads): void
    {
        Http::fake([
            '*/act_123/ads*' => Http::response(['data' => [['id' => 'ad-1']]]),
            '*/ad-1/leads*' => Http::response(['data' => $leads]),
            'connect.mailerlite.com/*' => Http::response(['data' => ['id' => '1']]),
        ]);
    }

    private function lead(string $id, string $email, string $name = 'Sam Smith'): array
    {
        return [
            'id' => $id,
            'created_time' => now()->subDay()->toIso8601String(),
            'field_data' => [
                ['name' => 'email', 'values' => [$email]],
                ['name' => 'full_name', 'values' => [$name]],
            ],
        ];
    }

    public function test_form_leads_become_subscribers(): void
    {
        $this->fakeLeads([$this->lead('l1', 'Backer@Example.com'), $this->lead('l2', 'second@example.com')]);

        $project = Project::factory()->create();
        $this->connectMeta($project);

        $result = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(2, $result['imported']);
        $this->assertDatabaseHas('subscribers', [
            'project_id' => $project->id,
            'email' => 'backer@example.com',
            'external_id' => 'l1',
            'source' => 'meta_lead_form',
        ]);
    }

    public function test_importing_twice_does_not_duplicate(): void
    {
        $this->fakeLeads([$this->lead('l1', 'backer@example.com')]);

        $project = Project::factory()->create();
        $this->connectMeta($project);

        app(ImportMetaLeads::class)->handle($project);
        $second = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(0, $second['imported']);
        $this->assertDatabaseCount('subscribers', 1);
    }

    public function test_leads_are_forwarded_to_mailerlite_when_connected(): void
    {
        $this->fakeLeads([$this->lead('l1', 'backer@example.com')]);

        $project = Project::factory()->create();
        $this->connectMeta($project);
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
        ]);

        $result = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(1, $result['forwarded']);
        $this->assertNotNull(Subscriber::first()->synced_to_email_at);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'mailerlite')
            && $request['email'] === 'backer@example.com');
    }

    public function test_a_subscriber_is_only_forwarded_once(): void
    {
        $this->fakeLeads([$this->lead('l1', 'backer@example.com')]);

        $project = Project::factory()->create();
        $this->connectMeta($project);
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
        ]);

        app(ImportMetaLeads::class)->handle($project);
        $second = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(0, $second['forwarded']);
    }

    public function test_leads_without_an_email_are_skipped_not_fatal(): void
    {
        $this->fakeLeads([
            ['id' => 'l1', 'field_data' => [['name' => 'phone_number', 'values' => ['0700']]]],
            $this->lead('l2', 'good@example.com'),
        ]);

        $project = Project::factory()->create();
        $this->connectMeta($project);

        $result = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_leads_are_kept_when_mailerlite_is_not_connected(): void
    {
        $this->fakeLeads([$this->lead('l1', 'backer@example.com')]);

        $project = Project::factory()->create();
        $this->connectMeta($project);

        $result = app(ImportMetaLeads::class)->handle($project);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['forwarded']);
    }

    public function test_the_command_reports_what_it_did(): void
    {
        $this->fakeLeads([$this->lead('l1', 'backer@example.com')]);

        $project = Project::factory()->create(['name' => 'Totally Football']);
        $this->connectMeta($project);

        $this->artisan('meta:import-leads')
            ->expectsOutputToContain('1 imported')
            ->assertSuccessful();
    }

    public function test_kickstarter_follows_are_counted_apart_from_form_signups(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'ad_id' => '1',
            'ad_name' => 'Kickstarter Preview',
            'date_stop' => now()->subDay()->toDateString(),
            'spend' => '20.00',
            'impressions' => '2000',
            'clicks' => '60',
            'actions' => [
                ['action_type' => 'offsite_conversion.fb_pixel_lead', 'value' => '14'],
                ['action_type' => 'leadgen_grouped', 'value' => '6'],
                ['action_type' => 'lead', 'value' => '20'],
            ],
        ]]])]);

        $project = Project::factory()->create();
        // Pixel "Lead" on the Kickstarter page means a follow, not a contact.
        $this->connectMeta($project, [
            'follow_actions' => ['offsite_conversion.fb_pixel_lead'],
            'lead_actions' => ['leadgen_grouped', 'onsite_conversion.lead_grouped'],
        ]);

        app(RunIntegrationSync::class)->handle($project, 'meta');

        $value = fn (string $metric) => (float) MetricSnapshot::where('metric', $metric)->sum('value');

        $this->assertSame(14.0, $value('ad_follows'));
        $this->assertSame(6.0, $value('ad_leads'), 'form signups must exclude follows');
    }

    public function test_follows_appear_in_the_kickstarter_step_of_the_funnel(): void
    {
        $project = Project::factory()->create();

        app(\App\Services\Analytics\MetricRecorder::class)->record(
            $project, 'meta', 'ad_follows', 14, now()->subDay(), ['ad_id' => '1'],
        );

        \Laravel\Sanctum\Sanctum::actingAs($project->user);

        $funnel = collect($this->getJson("/api/projects/{$project->id}/dashboard")->json('funnel'))
            ->keyBy('key');

        $this->assertSame(14, $funnel['kickstarter_notify']['value']);
    }
}
