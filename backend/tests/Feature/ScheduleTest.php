<?php

namespace Tests\Feature;

use App\Actions\ImportMetaLeads;
use App\Actions\ImportStripeVips;
use App\Jobs\ImportLeadsForAllProjects;
use App\Models\Integration;
use App\Models\Project;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return Collection<int, Event> */
    private function events(): Collection
    {
        return collect(app(Schedule::class)->events());
    }

    private function event(string $name): Event
    {
        $event = $this->events()->first(fn (Event $e) => $e->description === $name);

        $this->assertNotNull($event, "no scheduled event named {$name}");

        return $event;
    }

    public function test_leads_are_polled_every_five_minutes(): void
    {
        // A welcome email an hour after signing up has lost most of its
        // effect, so the poll runs far more often than the metric syncs.
        $this->assertSame('*/5 * * * *', $this->event('import-leads-poll')->expression);
        $this->assertSame('0 * * * *', $this->event('import-leads-sweep')->expression);
    }

    public function test_the_two_lead_schedules_do_not_share_a_lock(): void
    {
        // Schedule::job() names an event after its job class, so both would
        // take the same mutex. The poll is registered first and would win
        // every hour, leaving the wide sweep permanently skipped.
        $this->assertNotSame(
            $this->event('import-leads-poll')->mutexName(),
            $this->event('import-leads-sweep')->mutexName(),
        );
    }

    public function test_every_overlap_lock_expires_well_inside_its_own_interval(): void
    {
        // Laravel holds an overlap lock for 24 hours by default. A run that
        // dies without releasing it would then stop that job for a day.
        $guarded = $this->events()->filter(fn (Event $e) => $e->withoutOverlapping);

        $this->assertNotEmpty($guarded);

        foreach ($guarded as $event) {
            $this->assertLessThanOrEqual(
                60,
                $event->expiresAt,
                "{$event->description} holds its lock for {$event->expiresAt} minutes",
            );
        }
    }

    public function test_the_job_passes_its_window_to_both_importers(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'meta', 'status' => 'connected']);
        Integration::factory()->for($project)->create(['provider' => 'stripe', 'status' => 'connected']);

        $meta = $this->mock(ImportMetaLeads::class);
        $stripe = $this->mock(ImportStripeVips::class);

        $meta->shouldReceive('handle')->once()->with($this->anything(), 1)->andReturn([]);
        $stripe->shouldReceive('handle')->once()->with($this->anything(), 1)->andReturn([]);

        (new ImportLeadsForAllProjects(days: 1))->handle($meta, $stripe);
    }

    public function test_a_disconnected_integration_is_left_alone(): void
    {
        $project = Project::factory()->create();
        Integration::factory()->for($project)->create(['provider' => 'meta', 'status' => 'error']);

        $meta = $this->mock(ImportMetaLeads::class);
        $stripe = $this->mock(ImportStripeVips::class);

        $meta->shouldNotReceive('handle');
        $stripe->shouldNotReceive('handle');

        (new ImportLeadsForAllProjects)->handle($meta, $stripe);
    }
}
