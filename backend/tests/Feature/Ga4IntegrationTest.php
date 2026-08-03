<?php

namespace Tests\Feature;

use App\Integrations\IntegrationManager;
use App\Integrations\Support\GoogleServiceAccount;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class Ga4IntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** A throwaway service account key, generated per test run. */
    private function serviceAccountKey(): string
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKey);

        return json_encode([
            'type' => 'service_account',
            'client_email' => 'launch-os@example.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]);
    }

    public function test_connecting_rejects_a_key_that_is_not_valid_json(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/integrations/ga4/connect", [
            'credentials' => ['property_id' => '123', 'service_account_json' => 'not json'],
        ])->assertUnprocessable();
    }

    public function test_connecting_rejects_a_key_without_a_private_key(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->postJson("/api/projects/{$project->id}/integrations/ga4/connect", [
            'credentials' => [
                'property_id' => '123',
                'service_account_json' => json_encode(['client_email' => 'a@b.com', 'private_key' => 'nope']),
            ],
        ])->assertUnprocessable();
    }

    public function test_syncing_exchanges_the_key_for_a_token_and_records_metrics(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'ya29.fresh-token']),
            'analyticsdata.googleapis.com/*' => Http::response([
                'rows' => [
                    [
                        'dimensionValues' => [['value' => '20260801']],
                        'metricValues' => [['value' => '412'], ['value' => '388'], ['value' => '901']],
                    ],
                ],
            ]),
        ]);

        $project = Project::factory()->create();
        Integration::factory()->for($project)->create([
            'provider' => 'ga4',
            'credentials' => [
                'property_id' => '987654321',
                'service_account_json' => $this->serviceAccountKey(),
            ],
        ]);

        $result = app(IntegrationManager::class)->for($project, 'ga4')->sync();

        $this->assertTrue($result->ok);

        $this->assertDatabaseHas('metric_snapshots', [
            'project_id' => $project->id,
            'source' => 'ga4',
            'metric' => 'sessions',
            'value' => 412,
        ]);

        // The report must be requested with the minted token, not the key.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'analyticsdata')
            && $request->hasHeader('Authorization', 'Bearer ya29.fresh-token'));
    }

    public function test_the_signed_assertion_is_a_well_formed_jwt(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok'])]);

        $key = GoogleServiceAccount::parseKey($this->serviceAccountKey());

        $token = app(GoogleServiceAccount::class)->accessToken($key, 'https://example.test/scope');

        $this->assertSame('tok', $token);

        Http::assertSent(function ($request) {
            $parts = explode('.', $request['assertion']);
            $this->assertCount(3, $parts, 'assertion should be header.payload.signature');

            $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
            $claims = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

            $this->assertSame('RS256', $header['alg']);
            $this->assertSame('launch-os@example.iam.gserviceaccount.com', $claims['iss']);
            $this->assertSame('https://oauth2.googleapis.com/token', $claims['aud']);
            $this->assertGreaterThan(time(), $claims['exp']);

            return true;
        });
    }

    public function test_the_wizard_exposes_field_help_and_docs_links(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $response = $this->getJson("/api/projects/{$project->id}/integrations")->assertOk();

        $ga4 = collect($response->json('integrations'))->firstWhere('provider', 'ga4');

        $this->assertSame('textarea', $ga4['credential_fields']['service_account_json']['type']);
        $this->assertNotEmpty($ga4['credential_fields']['service_account_json']['help']);
        $this->assertNotEmpty($ga4['docs_url']);

        $meta = collect($response->json('integrations'))->firstWhere('provider', 'meta');
        $this->assertStringContainsString('act_', $meta['credential_fields']['ad_account_id']['help']);
    }
}
