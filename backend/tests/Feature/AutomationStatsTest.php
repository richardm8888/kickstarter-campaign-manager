<?php

namespace Tests\Feature;

use App\Integrations\Providers\MailerLiteIntegration;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\EmailPerformance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Automation sequences, which were invisible.
 *
 * `email_opens` and friends come from the campaigns endpoint, which
 * holds broadcasts only. On the account this was written against, three
 * broadcasts had sent 167 emails and one welcome sequence had sent 227 —
 * so the Email tab was describing the smaller half of the programme.
 *
 * The fixtures below are the live API's actual shape, read from it
 * rather than remembered.
 */
class AutomationStatsTest extends TestCase
{
    use RefreshDatabase;

    private function connect(Project $project): void
    {
        Integration::factory()->for($project)->create([
            'provider' => 'mailerlite',
            'credentials' => ['api_key' => 'ml-key'],
            'settings' => [],
        ]);
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function automation(string $id, string $name, array $stats, array $steps = []): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'enabled' => true,
            'emails_count' => count($steps),
            'stats' => $stats,
            'steps' => $steps,
        ];
    }

    private function step(string $id, string $name, string $subject, array $stats): array
    {
        return [
            'id' => $id,
            'type' => 'email',
            'name' => $name,
            'subject' => $subject,
            'email' => ['stats' => $stats],
        ];
    }

    private function fake(array $automations, array $campaigns = []): void
    {
        $detail = [];

        foreach ($automations as $automation) {
            $detail["connect.mailerlite.com/api/automations/{$automation['id']}"]
                = Http::response(['data' => $automation]);
        }

        Http::fake($detail + [
            'connect.mailerlite.com/api/automations*' => Http::response(['data' => $automations]),
            'connect.mailerlite.com/api/campaigns/*/links' => Http::response(['data' => []]),
            'connect.mailerlite.com/api/campaigns*' => Http::response(['data' => $campaigns]),
            'connect.mailerlite.com/api/subscribers*' => Http::response(['data' => [], 'total' => 213]),
            'connect.mailerlite.com/api/groups*' => Http::response(['data' => []]),
        ]);
    }

    public function test_a_sequence_and_its_emails_are_recorded(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->fake([
            $this->automation('a1', 'Totally Football Workflow', [
                'sent' => 227, 'opens_count' => 99, 'clicks_count' => 17, 'unsubscribes_count' => 5,
            ], [
                $this->step('s1', 'Welcome', 'Thanks for joining the squad', [
                    'sent' => 227, 'opens_count' => 99, 'clicks_count' => 17,
                ]),
            ]),
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $performance = app(EmailPerformance::class)->build($project, 30);
        $automation = $performance['automations'][0];

        $this->assertSame('Totally Football Workflow', $automation['name']);
        $this->assertSame(227, $automation['sent']);
        $this->assertSame(99, $automation['opens']);
        $this->assertEqualsWithDelta(43.6, $automation['open_rate'], 0.1);
        $this->assertEqualsWithDelta(7.5, $automation['click_rate'], 0.1);

        $this->assertCount(1, $automation['steps']);
        $this->assertSame('Welcome', $automation['steps'][0]['name']);
        $this->assertSame(227, $automation['steps'][0]['sent']);
    }

    public function test_a_running_total_is_not_multiplied_by_the_number_of_syncs(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->fake([
            $this->automation('a1', 'Welcome Series 1', [
                'sent' => 227, 'opens_count' => 99, 'clicks_count' => 17, 'unsubscribes_count' => 5,
            ]),
        ]);

        $integration = app(MailerLiteIntegration::class, ['project' => $project]);
        $integration->sync();
        $integration->sync();
        $integration->sync();

        // Three observations of one running total, not 681 emails.
        $this->assertSame(227, app(EmailPerformance::class)->build($project, 30)['automations'][0]['sent']);
    }

    public function test_sequences_are_ordered_by_how_much_they_send(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->fake([
            $this->automation('a1', 'Starting XI Welcome', ['sent' => 40, 'opens_count' => 12, 'clicks_count' => 2, 'unsubscribes_count' => 0]),
            $this->automation('a2', 'Totally Football Workflow', ['sent' => 227, 'opens_count' => 99, 'clicks_count' => 17, 'unsubscribes_count' => 5]),
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $names = array_column(app(EmailPerformance::class)->build($project, 30)['automations'], 'name');

        $this->assertSame(['Totally Football Workflow', 'Starting XI Welcome'], $names);
    }

    public function test_a_sequence_too_small_to_rate_reports_no_rate(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        $this->fake([
            $this->automation('a1', 'Brand new', ['sent' => 4, 'opens_count' => 3, 'clicks_count' => 1, 'unsubscribes_count' => 0]),
        ]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $automation = app(EmailPerformance::class)->build($project, 30)['automations'][0];

        $this->assertSame(4, $automation['sent']);
        $this->assertNull($automation['open_rate'], 'Three opens of four sends is not a 75% open rate.');
    }

    public function test_it_says_how_much_of_the_sending_is_automated(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);

        // The real proportions: three broadcasts against one sequence.
        $this->fake(
            [$this->automation('a1', 'Totally Football Workflow', [
                'sent' => 227, 'opens_count' => 99, 'clicks_count' => 17, 'unsubscribes_count' => 5,
            ])],
            [
                ['id' => 'c1', 'name' => 'Kickstarter pre-campaign launch', 'finished_at' => now()->subDays(5)->toDateTimeString(), 'stats' => ['sent' => 60]],
                ['id' => 'c2', 'name' => 'Box Design', 'finished_at' => now()->subDays(14)->toDateTimeString(), 'stats' => ['sent' => 60]],
                ['id' => 'c3', 'name' => 'Prototype Reveal', 'finished_at' => now()->subDays(29)->toDateTimeString(), 'stats' => ['sent' => 47]],
            ],
        );

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $totals = app(EmailPerformance::class)->build($project, 30)['totals'];

        $this->assertSame(227, $totals['automated_sent']);
        $this->assertSame(167, $totals['broadcast_sent']);
        // The point of the whole feature: most of the sending was hidden.
        $this->assertEqualsWithDelta(57.6, $totals['automated_share'], 0.5);
    }

    public function test_an_account_with_no_automations_says_nothing_rather_than_zero(): void
    {
        $project = Project::factory()->create();
        $this->connect($project);
        $this->fake([]);

        app(MailerLiteIntegration::class, ['project' => $project])->sync();

        $performance = app(EmailPerformance::class)->build($project, 30);

        $this->assertSame([], $performance['automations']);
        $this->assertNull($performance['totals']);
    }

    public function test_it_reaches_the_email_tab(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);

        $this->getJson("/api/projects/{$project->id}/analytics?category=email")
            ->assertOk()
            ->assertJsonStructure(['email_performance' => ['automations', 'totals']]);

        $this->getJson("/api/projects/{$project->id}/analytics?category=ads")
            ->assertOk()
            ->assertJsonPath('email_performance', null);
    }
}
