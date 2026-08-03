<?php

namespace Tests\Feature;

use App\Actions\ImportMetaLeads;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MailerLiteGroupTest extends TestCase
{
    use RefreshDatabase;

    private function connectMailerLite(Project $project, array $settings = []): Integration
    {
        return Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => $settings,
        ]);
    }

    private function fakeGroups(): void
    {
        Http::fake([
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Launch list', 'active_count' => 143],
                ['id' => '222', 'name' => 'VIPs', 'active_count' => 12],
            ]]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => ['id' => '1']]),
        ]);
    }

    public function test_it_lists_the_accounts_groups(): void
    {
        $this->fakeGroups();

        $project = Project::factory()->create();
        $this->connectMailerLite($project);
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/integrations/mailerlite/groups")
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('groups.0.name', 'Launch list')
            ->assertJsonPath('groups.0.total', 143)
            ->assertJsonPath('group_id', null)
            ->assertJsonPath('vip_group_id', null);
    }

    public function test_it_reports_when_mailerlite_is_not_connected(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/integrations/mailerlite/groups")
            ->assertOk()
            ->assertJsonPath('connected', false)
            ->assertJsonPath('groups', []);
    }

    public function test_a_group_can_be_chosen_and_cleared(): void
    {
        $project = Project::factory()->create();
        $integration = $this->connectMailerLite($project);
        Sanctum::actingAs($project->user);

        $this->putJson("/api/projects/{$project->id}/integrations/mailerlite/group", ['group_id' => '111'])
            ->assertOk()
            ->assertJsonPath('group_id', '111');

        $this->assertSame('111', $integration->fresh()->settings['group_id']);

        $this->putJson("/api/projects/{$project->id}/integrations/mailerlite/group", ['group_id' => null])
            ->assertOk();

        $this->assertNull($integration->fresh()->settings['group_id']);
    }

    public function test_imported_leads_join_the_chosen_group(): void
    {
        Http::fake([
            '*/act_123/ads*' => Http::response(['data' => [['id' => 'ad-1', 'name' => 'Form ad']]]),
            '*/ad-1/leads*' => Http::response(['data' => [[
                'id' => 'l1',
                'field_data' => [['name' => 'email', 'values' => ['backer@example.com']]],
            ]]]),
            'connect.mailerlite.com/*' => Http::response(['data' => ['id' => '1']]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
        ]);
        $this->connectMailerLite($project, ['group_id' => '111']);

        app(ImportMetaLeads::class)->handle($project);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api/subscribers')
            && $request['groups'] === ['111']);
    }

    public function test_no_group_is_sent_when_none_is_configured(): void
    {
        Http::fake([
            '*/act_123/ads*' => Http::response(['data' => [['id' => 'ad-1', 'name' => 'Form ad']]]),
            '*/ad-1/leads*' => Http::response(['data' => [[
                'id' => 'l1',
                'field_data' => [['name' => 'email', 'values' => ['backer@example.com']]],
            ]]]),
            'connect.mailerlite.com/*' => Http::response(['data' => ['id' => '1']]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
        ]);
        $this->connectMailerLite($project);

        app(ImportMetaLeads::class)->handle($project);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api/subscribers')
            || ! isset($request['groups']));
    }

    public function test_other_users_cannot_change_the_group(): void
    {
        $project = Project::factory()->create();
        $this->connectMailerLite($project);

        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->putJson("/api/projects/{$project->id}/integrations/mailerlite/group", ['group_id' => '111'])
            ->assertForbidden();
    }

    public function test_a_vip_group_can_be_set_and_its_count_recorded(): void
    {
        $this->fakeGroups();

        $project = Project::factory()->create();
        $this->connectMailerLite($project);
        Sanctum::actingAs($project->user);

        $this->putJson("/api/projects/{$project->id}/integrations/mailerlite/group", [
            'group_id' => '111',
            'vip_group_id' => '222',
        ])->assertOk()->assertJsonPath('vip_group_id', '222');

        // The sync records each configured group's size.
        Http::fake([
            'connect.mailerlite.com/api/subscribers?limit=0*' => Http::response(['total' => 155]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => [
                ['id' => '111', 'name' => 'Launch list', 'active_count' => 143],
                ['id' => '222', 'name' => 'VIPs', 'active_count' => 12],
            ]]),
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => []]),
            'connect.mailerlite.com/*' => Http::response(['data' => [], 'total' => 155]),
        ]);

        app(\App\Actions\RunIntegrationSync::class)->handle($project->fresh(), 'mailerlite');

        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'metric' => 'email_vip_subscribers',
            'value' => 12,
        ]);
    }

    public function test_the_vip_count_reaches_the_dashboard(): void
    {
        $project = Project::factory()->create();
        app(\App\Services\Analytics\MetricRecorder::class)
            ->record($project, 'mailerlite', 'email_vip_subscribers', 12);

        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('cards.vip_upgrades.value', 12);
    }
}
