<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MetaPixelProbeTest extends TestCase
{
    use RefreshDatabase;

    private function connected(): Project
    {
        $project = Project::factory()->create();

        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => ['access_token' => 'tok', 'ad_account_id' => '123'],
        ]);

        return $project;
    }

    public function test_it_reports_which_shapes_of_event_data_come_back(): void
    {
        Http::fake([
            '*/adspixels*' => Http::response(['data' => [
                ['id' => '99', 'name' => 'Totally Football', 'last_fired_time' => '2026-08-12T09:00:00+0000'],
            ]]),
            '*/99/stats*' => Http::response(['data' => [
                ['value' => 412, 'event' => 'Lead'],
            ]]),
            '*' => Http::response(['id' => '99', 'name' => 'Totally Football']),
        ]);

        $this->connected();

        $this->artisan('meta:pixel-probe')
            ->expectsOutputToContain('Totally Football')
            ->assertSuccessful();
    }

    public function test_a_removed_edge_reports_metas_own_words(): void
    {
        // The error message is the answer: Meta says whether an edge is
        // gone, needs a permission, or was asked wrongly, and guessing
        // between those is what this command exists to avoid.
        Http::fake([
            '*/adspixels*' => Http::response(['data' => [['id' => '99', 'name' => 'Pixel']]]),
            '*' => Http::response([
                'error' => ['message' => 'Unknown path components: /stats'],
            ], 400),
        ]);

        $this->connected();

        $this->artisan('meta:pixel-probe')
            ->expectsOutputToContain('Unknown path components')
            ->assertSuccessful();
    }

    public function test_incomplete_credentials_fail_with_a_fix_rather_than_a_stack_trace(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'meta',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'wrong shape'],
        ]);

        $this->artisan('meta:pixel-probe')
            ->expectsOutputToContain('Reconnect it on the Integrations page')
            ->assertFailed();
    }

    public function test_it_says_when_the_account_has_no_pixel(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->connected();

        $this->artisan('meta:pixel-probe')
            ->expectsOutputToContain('No pixels on this ad account')
            ->assertSuccessful();
    }

    // The same probe from the Ads page. The person who needs this answer
    // is usually holding a phone, so the terminal route is not enough.

    public function test_the_ads_page_gets_the_same_answer_the_command_prints(): void
    {
        Http::fake([
            '*/adspixels*' => Http::response(['data' => [
                ['id' => '99', 'name' => 'Totally Football', 'last_fired_time' => '2026-08-12T09:00:00+0000'],
            ]]),
            '*/99/stats*' => Http::response(['data' => [['value' => 412, 'event' => 'Lead']]]),
            '*' => Http::response(['id' => '99', 'name' => 'Totally Football']),
        ]);

        $project = $this->connected();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/ads/pixel-probe")
            ->assertOk()
            ->assertJsonPath('pixels.0.name', 'Totally Football')
            ->assertJsonPath('pixels.0.checks.0.label', 'Totals by event')
            ->assertJsonPath('pixels.0.checks.0.ok', true)
            ->assertJsonPath('pixels.0.checks.0.rows', 1);
    }

    public function test_missing_credentials_come_back_as_something_to_do(): void
    {
        $project = Project::factory()->create();
        $project->integrations()->create([
            'provider' => 'meta',
            'status' => Integration::STATUS_CONNECTED,
            'credentials' => ['api_key' => 'wrong shape'],
        ]);

        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/ads/pixel-probe")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Reconnect it on the Integrations page'));
    }

    public function test_someone_elses_project_cannot_be_probed(): void
    {
        Http::fake();

        $project = $this->connected();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/projects/{$project->id}/ads/pixel-probe")->assertForbidden();

        // Nothing was spent against the owner's Meta token.
        Http::assertNothingSent();
    }
}
