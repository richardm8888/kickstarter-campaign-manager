<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LandingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_creator_can_update_section_content(): void
    {
        Sanctum::actingAs($user = \App\Models\User::factory()->create());

        $this->postJson('/api/projects', ['name' => 'Totally Football'])->assertCreated();

        $project = Project::firstWhere('slug', 'totally-football');
        $hero = $project->landingPage->sections()->where('type', 'hero')->first();

        $this->putJson("/api/projects/{$project->id}/landing-page/sections", [
            'sections' => [[
                'id' => $hero->id,
                'content' => ['headline' => 'The totally tactical football game', 'image' => '/hero.jpg'],
            ]],
        ])->assertOk();

        $this->assertSame(
            'The totally tactical football game',
            $hero->fresh()->content['headline'],
        );
    }

    public function test_unpublished_pages_are_not_publicly_visible(): void
    {
        $page = LandingPage::factory()->create(['published' => false]);

        $this->getJson("/api/pages/{$page->slug}")->assertNotFound();
    }

    public function test_published_pages_expose_only_enabled_sections(): void
    {
        $page = LandingPage::factory()->create(['published' => true]);
        $page->sections()->create(['type' => 'hero', 'position' => 0, 'enabled' => true, 'content' => []]);
        $page->sections()->create(['type' => 'faq', 'position' => 1, 'enabled' => false, 'content' => []]);

        $response = $this->getJson("/api/pages/{$page->slug}")->assertOk();

        $types = collect($response->json('landing_page.sections'))->pluck('type');
        $this->assertTrue($types->contains('hero'));
        $this->assertFalse($types->contains('faq'));
    }

    public function test_visitors_can_subscribe_and_upgrade_to_vip(): void
    {
        $page = LandingPage::factory()->create(['published' => true]);

        $this->postJson("/api/pages/{$page->slug}/subscribe", ['email' => 'Fan@Example.com'])
            ->assertCreated();

        $this->assertDatabaseHas('subscribers', [
            'project_id' => $page->project_id,
            'email' => 'fan@example.com',
            'is_vip' => false,
        ]);

        $this->postJson("/api/pages/{$page->slug}/vip", ['email' => 'fan@example.com'])->assertOk();

        $this->assertDatabaseHas('subscribers', ['email' => 'fan@example.com', 'is_vip' => true]);

        // Signup and VIP conversion are recorded in the append-only store.
        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'signups', 'source' => 'landing_page']);
        $this->assertDatabaseHas('metric_snapshots', ['metric' => 'vip_upgrades', 'source' => 'landing_page']);
    }

    public function test_duplicate_subscriptions_are_idempotent(): void
    {
        $page = LandingPage::factory()->create(['published' => true]);

        $this->postJson("/api/pages/{$page->slug}/subscribe", ['email' => 'fan@example.com']);
        $this->postJson("/api/pages/{$page->slug}/subscribe", ['email' => 'fan@example.com']);

        $this->assertDatabaseCount('subscribers', 1);
    }
}
