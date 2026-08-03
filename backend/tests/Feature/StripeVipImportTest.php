<?php

namespace Tests\Feature;

use App\Actions\ImportStripeVips;
use App\Models\Integration;
use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StripeVipImportTest extends TestCase
{
    use RefreshDatabase;

    private function connectStripe(Project $project, array $settings = []): Integration
    {
        return Integration::factory()->for($project)->create([
            'provider' => 'stripe',
            'credentials' => ['secret_key' => 'sk_test'],
            'settings' => $settings,
        ]);
    }

    private function checkoutSession(string $id, string $email, string $productId, string $status = 'paid'): array
    {
        return [
            'id' => $id,
            'payment_status' => $status,
            'amount_total' => 100,
            'created' => now()->subDay()->timestamp,
            'customer_details' => ['email' => $email],
            'line_items' => ['data' => [['price' => ['product' => $productId]]]],
        ];
    }

    private function fakeStripe(array $sessions): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions*' => Http::response(['data' => $sessions]),
            'api.stripe.com/v1/products*' => Http::response(['data' => [
                ['id' => 'prod_vip', 'name' => '£1 VIP upgrade'],
                ['id' => 'prod_other', 'name' => 'T-shirt'],
            ]]),
            'connect.mailerlite.com/*' => Http::response(['data' => ['id' => '1']]),
        ]);
    }

    public function test_only_purchases_of_the_configured_products_create_vips(): void
    {
        $this->fakeStripe([
            $this->checkoutSession('cs_1', 'vip@example.com', 'prod_vip'),
            $this->checkoutSession('cs_2', 'shopper@example.com', 'prod_other'),
        ]);

        $project = Project::factory()->create();
        $this->connectStripe($project, ['vip_product_ids' => ['prod_vip']]);

        $result = app(ImportStripeVips::class)->handle($project);

        $this->assertSame(1, $result['found']);
        $this->assertSame(1, $result['imported']);

        $this->assertDatabaseHas('subscribers', ['email' => 'vip@example.com', 'is_vip' => true]);
        $this->assertDatabaseMissing('subscribers', ['email' => 'shopper@example.com']);
    }

    public function test_nothing_happens_until_products_are_chosen(): void
    {
        $this->fakeStripe([$this->checkoutSession('cs_1', 'vip@example.com', 'prod_vip')]);

        $project = Project::factory()->create();
        $this->connectStripe($project);

        $this->assertSame(0, app(ImportStripeVips::class)->handle($project)['found']);
        $this->assertDatabaseCount('subscribers', 0);
    }

    public function test_unpaid_sessions_are_ignored(): void
    {
        $this->fakeStripe([$this->checkoutSession('cs_1', 'abandoned@example.com', 'prod_vip', 'unpaid')]);

        $project = Project::factory()->create();
        $this->connectStripe($project, ['vip_product_ids' => ['prod_vip']]);

        $this->assertSame(0, app(ImportStripeVips::class)->handle($project)['found']);
    }

    public function test_an_existing_subscriber_is_upgraded_rather_than_duplicated(): void
    {
        $this->fakeStripe([$this->checkoutSession('cs_1', 'known@example.com', 'prod_vip')]);

        $project = Project::factory()->create();
        Subscriber::factory()->for($project)->create(['email' => 'known@example.com', 'is_vip' => false]);
        $this->connectStripe($project, ['vip_product_ids' => ['prod_vip']]);

        app(ImportStripeVips::class)->handle($project);

        $this->assertDatabaseCount('subscribers', 1);
        $this->assertTrue(Subscriber::first()->is_vip);
    }

    public function test_vips_are_added_to_the_vip_group_not_the_default_group(): void
    {
        $this->fakeStripe([$this->checkoutSession('cs_1', 'vip@example.com', 'prod_vip')]);

        $project = Project::factory()->create();
        $this->connectStripe($project, ['vip_product_ids' => ['prod_vip']]);
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => ['group_id' => '111', 'vip_group_id' => '222'],
        ]);

        $result = app(ImportStripeVips::class)->handle($project);

        $this->assertSame(1, $result['forwarded']);

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'api/subscribers')
            || $request['groups'] === ['222']);
    }

    public function test_running_twice_does_not_re_add_or_double_count(): void
    {
        $this->fakeStripe([$this->checkoutSession('cs_1', 'vip@example.com', 'prod_vip')]);

        $project = Project::factory()->create();
        $this->connectStripe($project, ['vip_product_ids' => ['prod_vip']]);

        app(ImportStripeVips::class)->handle($project);
        $second = app(ImportStripeVips::class)->handle($project);

        $this->assertSame(0, $second['imported']);
        $this->assertSame(1, $second['already_vip']);
        $this->assertDatabaseCount('subscribers', 1);

        // vip_upgrades is an event metric: it must be recorded once only.
        $this->assertSame(
            1.0,
            (float) \App\Models\MetricSnapshot::where('metric', 'vip_upgrades')->sum('value'),
        );
    }

    public function test_products_can_be_listed_and_chosen_in_settings(): void
    {
        $this->fakeStripe([]);

        $project = Project::factory()->create();
        $this->connectStripe($project);
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/integrations/stripe/products")
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('products.0.name', '£1 VIP upgrade')
            ->assertJsonPath('vip_product_ids', []);

        $this->putJson("/api/projects/{$project->id}/integrations/stripe/products", [
            'vip_product_ids' => ['prod_vip'],
        ])->assertOk()->assertJsonPath('vip_product_ids.0', 'prod_vip');

        $this->assertSame(
            ['prod_vip'],
            $project->integrations()->where('provider', 'stripe')->first()->settings['vip_product_ids'],
        );
    }

    public function test_settings_are_scoped_to_the_owner(): void
    {
        $project = Project::factory()->create();
        $this->connectStripe($project);

        Sanctum::actingAs(\App\Models\User::factory()->create());

        $this->putJson("/api/projects/{$project->id}/integrations/stripe/products", [
            'vip_product_ids' => ['prod_vip'],
        ])->assertForbidden();
    }
}
