<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
