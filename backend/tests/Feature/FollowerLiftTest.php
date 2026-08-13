<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Analytics\FollowerLift;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Follower lift is an inference, so it is tested in both directions: it
 * has to find a real send's effect, and it has to keep quiet when the
 * data cannot support a number. A confident figure invented out of two
 * data points would get planned against.
 */
class FollowerLiftTest extends TestCase
{
    use RefreshDatabase;

    private MetricRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = app(MetricRecorder::class);
    }

    /** A follower count for each of the last $days days, growing by $perDay. */
    private function followers(Project $project, int $days, float $start, float $perDay, array $bumps = []): void
    {
        $value = $start;

        for ($back = $days; $back >= 0; $back--) {
            $date = now()->subDays($back)->toDateString();
            $value += $perDay + ($bumps[$date] ?? 0);

            $this->recorder->record($project, 'kickstarter', 'ks_followers', $value, $date);
        }
    }

    private function send(Project $project, string $date, string $name = 'Launch update', int $recipients = 100): void
    {
        $this->recorder->record($project, 'mailerlite', 'email_campaign_sent', $recipients, $date, [
            'campaign_id' => md5($date.$name),
            'campaign_name' => $name,
            'subject' => 'We are nearly there',
        ]);
    }

    public function test_it_finds_the_followers_a_send_brought_in(): void
    {
        $project = Project::factory()->create();
        $sendDate = now()->subDays(10)->toDateString();

        // One a day normally, plus twelve on the day of the send.
        $this->followers($project, 40, 100, 1, [$sendDate => 12]);
        $this->send($project, $sendDate);

        $result = app(FollowerLift::class)->build($project, 90);

        $this->assertCount(1, $result['sends']);
        $send = $result['sends'][0];

        $this->assertSame('measured', $send['status']);
        // Three days of window at one a day is the baseline; the rest is
        // the send's, and it should be about the twelve that arrived.
        $this->assertEqualsWithDelta(12.0, $send['lift'], 1.5);
        $this->assertSame(1, $result['summary']['sends_measured']);
        $this->assertEqualsWithDelta(12.0, $result['summary']['per_send'], 1.5);
        // 12 followers from 100 emails.
        $this->assertEqualsWithDelta(120.0, $result['summary']['per_1000_recipients'], 15.0);
    }

    public function test_follows_bought_with_ads_are_not_credited_to_the_email(): void
    {
        $project = Project::factory()->create();
        $sendDate = now()->subDays(10)->toDateString();

        $this->followers($project, 40, 100, 1, [$sendDate => 12]);
        $this->send($project, $sendDate);

        // Every one of that day's extra follows was paid for.
        $this->recorder->record($project, 'meta', 'ad_follows', 12, $sendDate, ['ad_id' => '1']);

        $send = app(FollowerLift::class)->build($project, 90)['sends'][0];

        $this->assertSame('measured', $send['status']);
        $this->assertEqualsWithDelta(0.0, $send['lift'], 1.5);
    }

    public function test_a_send_with_no_follower_counts_around_it_claims_nothing(): void
    {
        $project = Project::factory()->create();

        $this->send($project, now()->subDays(10)->toDateString());

        $result = app(FollowerLift::class)->build($project, 90);

        $this->assertSame([], $result['sends']);
        $this->assertNotNull($result['note']);
    }

    public function test_a_send_too_early_for_a_baseline_says_so(): void
    {
        $project = Project::factory()->create();

        // The window has finished, but there are only two days of
        // history before it — not enough to know what a quiet day is.
        $this->followers($project, 6, 100, 1);
        $this->send($project, now()->subDays(4)->toDateString());

        $send = app(FollowerLift::class)->build($project, 90)['sends'][0];

        $this->assertSame('no_baseline', $send['status']);
        $this->assertNull($send['lift']);
        $this->assertNotNull($send['note']);
    }

    public function test_a_send_still_inside_its_window_says_it_is_too_soon(): void
    {
        $project = Project::factory()->create();

        $this->followers($project, 40, 100, 1);
        $this->send($project, now()->toDateString());

        $send = app(FollowerLift::class)->build($project, 90)['sends'][0];

        $this->assertSame('too_recent', $send['status']);
        $this->assertNull($send['lift']);
        $this->assertStringContainsString('still being measured', $send['note']);
    }

    public function test_two_sends_in_one_window_are_reported_as_inseparable(): void
    {
        $project = Project::factory()->create();
        $first = now()->subDays(10)->toDateString();
        $second = now()->subDays(9)->toDateString();

        $this->followers($project, 40, 100, 1, [$first => 8, $second => 6]);
        $this->send($project, $first, 'First');
        $this->send($project, $second, 'Second');

        $statuses = array_column(app(FollowerLift::class)->build($project, 90)['sends'], 'status');

        $this->assertSame(['shared', 'shared'], $statuses);
    }

    public function test_a_previous_send_does_not_become_the_baseline(): void
    {
        $project = Project::factory()->create();
        $earlier = now()->subDays(20)->toDateString();
        $later = now()->subDays(5)->toDateString();

        // A big earlier send. If its days counted as ordinary, the
        // baseline would rise and the later send would look flat.
        $this->followers($project, 40, 100, 1, [$earlier => 30, $later => 12]);
        $this->send($project, $earlier, 'Earlier');
        $this->send($project, $later, 'Later');

        $sends = collect(app(FollowerLift::class)->build($project, 90)['sends'])->keyBy('name');

        $this->assertSame('measured', $sends['Later']['status']);
        $this->assertGreaterThan(8.0, $sends['Later']['lift']);
    }

    public function test_the_same_send_reported_every_hour_is_counted_once(): void
    {
        $project = Project::factory()->create();
        $sendDate = now()->subDays(10)->toDateString();

        $this->followers($project, 40, 100, 1, [$sendDate => 12]);
        $this->send($project, $sendDate);
        $this->send($project, $sendDate);
        $this->send($project, $sendDate);

        $this->assertCount(1, app(FollowerLift::class)->build($project, 90)['sends']);
    }

    public function test_it_reaches_the_email_tab_and_nowhere_else(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/analytics?category=email")
            ->assertOk()
            ->assertJsonPath('follower_lift.lag_days', 3);

        $this->getJson("/api/projects/{$project->id}/analytics?category=traffic")
            ->assertOk()
            ->assertJsonPath('follower_lift', null);
    }
}
