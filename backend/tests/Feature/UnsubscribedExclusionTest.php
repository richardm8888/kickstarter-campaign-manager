<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Subscriber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Someone who has left is not an audience. Counting them inflates every
 * forecast built on the list, and the error grows as a campaign ages.
 */
class UnsubscribedExclusionTest extends TestCase
{
    use RefreshDatabase;

    private function projectWith(int $active, int $gone): Project
    {
        $project = Project::factory()->create();

        Subscriber::factory()->count($active)->for($project)->create();
        Subscriber::factory()->count($gone)->for($project)->create(['unsubscribed_at' => now()->subDay()]);

        return $project;
    }

    public function test_departed_contacts_are_left_out_of_the_audience(): void
    {
        $project = $this->projectWith(active: 30, gone: 10);

        $this->assertSame(30, app(\App\Services\Analytics\AudienceSize::class)->total($project));
    }

    public function test_a_departed_vip_stops_counting_as_a_vip(): void
    {
        $project = Project::factory()->create();
        Subscriber::factory()->for($project)->create(['is_vip' => true]);
        Subscriber::factory()->for($project)->create(['is_vip' => true, 'unsubscribed_at' => now()]);

        $this->assertSame(1, app(\App\Services\Analytics\AudienceSize::class)->vips($project));
    }

    public function test_the_row_is_kept_so_a_return_is_recognised(): void
    {
        // Deleting would let a departed contact be re-imported as new,
        // and would lose the fact that they left at all.
        $project = $this->projectWith(active: 1, gone: 1);

        $this->assertSame(2, $project->subscribers()->count());
        $this->assertSame(1, $project->subscribers()->active()->count());
    }

    public function test_signing_up_again_undoes_an_unsubscribe(): void
    {
        $project = Project::factory()->create();
        $page = $project->landingPage()->create(['template' => 'default', 'slug' => 'come-back', 'published' => true]);

        Subscriber::factory()->for($project)->create([
            'email' => 'returning@example.com',
            'unsubscribed_at' => now()->subMonth(),
        ]);

        $this->postJson("/api/pages/{$page->slug}/subscribe", ['email' => 'returning@example.com'])
            ->assertCreated();

        $this->assertSame(1, $project->subscribers()->active()->count());
    }

    public function test_the_forecast_shrinks_when_people_leave(): void
    {
        $project = $this->projectWith(active: 100, gone: 0);
        Sanctum::actingAs($project->user);

        $before = $this->getJson("/api/projects/{$project->id}/forecast")
            ->json('measured.email_subscribers');

        $project->subscribers()->limit(40)->update(['unsubscribed_at' => now()]);

        $after = $this->getJson("/api/projects/{$project->id}/forecast")
            ->json('measured.email_subscribers');

        $this->assertSame(100, $before);
        $this->assertSame(60, $after);
    }
}
