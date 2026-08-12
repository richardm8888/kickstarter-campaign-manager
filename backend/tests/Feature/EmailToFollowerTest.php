<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Subscriber;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The figure that used to be reported as an "email to follower conversion
 * rate". Nothing links the two populations — Kickstarter hands over a
 * follower total and no identities — so it is a ratio, and the parts of
 * it that can be measured are measured rather than inferred.
 */
class EmailToFollowerTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::factory()->create([
            'launch_date' => now()->addDays(45),
            'kickstarter_url' => 'https://www.kickstarter.com/projects/me/game',
            'funding_goal' => 500_00,
            'average_pledge' => 45_00,
        ]);
    }

    private function record(Project $project, string $metric, float $value, ?array $dimensions = null, string $source = 'kickstarter'): void
    {
        app(MetricRecorder::class)->record($project, $source, $metric, $value, now()->subDay(), $dimensions);
    }

    private function task(Project $project): ?array
    {
        Sanctum::actingAs($project->user);

        return collect($this->getJson("/api/projects/{$project->id}/daily")->json('tasks'))
            ->firstWhere('signal_key', 'bottleneck_email_to_follower');
    }

    public function test_follows_bought_by_ads_do_not_count_as_the_lists_doing(): void
    {
        // Otherwise running Kickstarter-page ads silences advice about an
        // email list that is doing nothing at all.
        $project = $this->project();
        Subscriber::factory()->count(200)->for($project)->create();

        $this->record($project, 'ks_followers', 80);
        $this->record($project, 'ad_follows', 80, ['ad_id' => '1'], 'meta');

        $task = $this->task($project);

        $this->assertNotNull($task, '80 bought follows are not 80 followers the list produced');
        $this->assertSame(0, $task['evidence']['organic_followers']);
    }

    public function test_a_list_producing_its_own_followers_is_left_alone(): void
    {
        $project = $this->project();
        Subscriber::factory()->count(200)->for($project)->create();

        $this->record($project, 'ks_followers', 80);

        $this->assertNull($this->task($project));
    }

    public function test_it_says_when_nobody_reaches_the_page_from_email(): void
    {
        // Measured, not inferred: the emails are not asking.
        $project = $this->project();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10);
        $this->record($project, 'ks_page_sessions_by_source', 40, ['source' => 'fb'], 'ga4');

        $this->assertStringContainsString(
            'Nothing has reached the Kickstarter page from your emails',
            $this->task($project)['why'],
        );
    }

    public function test_it_says_when_they_arrive_but_do_not_follow(): void
    {
        // The opposite half, and a completely different morning's work.
        $project = $this->project();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10);
        $this->record($project, 'ks_page_sessions_by_source', 31, ['source' => 'mailerlite'], 'ga4');

        $this->assertStringContainsString(
            '31 people reached the page from your emails',
            $this->task($project)['why'],
        );
    }

    public function test_it_claims_nothing_when_the_kickstarter_tag_is_absent(): void
    {
        // No Google Analytics ID on the Kickstarter page means no way to
        // see this, and silence beats a guess.
        $project = $this->project();
        Subscriber::factory()->count(200)->for($project)->create();
        $this->record($project, 'ks_followers', 10);

        $why = $this->task($project)['why'];

        $this->assertStringNotContainsString('reached the page', $why);
        $this->assertStringNotContainsString('Nothing has reached', $why);
    }

    public function test_nothing_claims_to_be_a_conversion_rate(): void
    {
        $project = $this->project();
        Subscriber::factory()->count(100)->for($project)->create();
        $this->record($project, 'ks_followers', 40);

        Sanctum::actingAs($project->user);
        $brief = $this->getJson("/api/projects/{$project->id}/daily")->json();

        $reassurances = implode(' ', $brief['nothing_to_worry_about']);

        $this->assertStringContainsString('40 followers against 100 subscribers', $reassurances);
        $this->assertStringNotContainsString('converting at', $reassurances);
    }
}
