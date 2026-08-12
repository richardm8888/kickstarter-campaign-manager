<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use App\Services\Analytics\MetricSeries;
use App\Services\Analytics\SnapshotCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A cache is only worth having if it is both quiet and honest, so both
 * halves are tested: that repeated reads stop reaching the database, and
 * that nothing reads differently because they did.
 */
class SnapshotCacheTest extends TestCase
{
    use RefreshDatabase;

    private function record(Project $project, string $metric, float $value, int $daysAgo): void
    {
        app(MetricRecorder::class)->record($project, 'ga4', $metric, $value, now()->subDays($daysAgo));
    }

    private function queryCount(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_asking_the_same_question_twice_only_asks_the_database_once(): void
    {
        $project = Project::factory()->create();
        $this->record($project, 'sessions', 10, 1);

        $series = app(MetricSeries::class);

        $first = $this->queryCount(fn () => $series->daily($project, 'sessions', 30));
        $second = $this->queryCount(fn () => $series->daily($project, 'sessions', 30));

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'The second identical read should not reach the database.');
    }

    public function test_a_different_window_is_a_different_question(): void
    {
        $project = Project::factory()->create();
        $this->record($project, 'sessions', 10, 1);
        $this->record($project, 'sessions', 99, 40);

        $series = app(MetricSeries::class);

        // The 7-day window must not be answered with the 90-day one.
        $this->assertSame(10.0, $series->sum($project, 'sessions', 7));
        $this->assertSame(109.0, $series->sum($project, 'sessions', 90));
    }

    public function test_a_source_filter_is_not_shared_with_an_unfiltered_read(): void
    {
        $project = Project::factory()->create();

        // Different days, because two observations of one day collapse to
        // the last of them — which would hide what this is testing.
        app(MetricRecorder::class)->record($project, 'ga4', 'sessions', 10, now()->subDays(2));
        app(MetricRecorder::class)->record($project, 'meta', 'sessions', 5, now()->subDay());

        $series = app(MetricSeries::class);

        $this->assertSame(15.0, $series->sum($project, 'sessions', 30));
        $this->assertSame(10.0, $series->sum($project, 'sessions', 30, 'ga4'));
    }

    public function test_a_sync_is_not_told_what_the_numbers_were_before_it_ran(): void
    {
        $project = Project::factory()->create();
        $series = app(MetricSeries::class);

        $this->record($project, 'sessions', 10, 1);
        $this->assertSame(10.0, $series->sum($project, 'sessions', 30));

        // Same request, same instance: a sync records and then reads.
        $this->record($project, 'sessions', 7, 0);

        $this->assertSame(
            17.0,
            $series->sum($project, 'sessions', 30),
            'A read after a write must see the write.',
        );
    }

    public function test_one_request_does_not_inherit_anothers_answers(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->user);
        $this->record($project, 'sessions', 10, 1);

        $this->getJson("/api/projects/{$project->id}/analytics?category=traffic")
            ->assertOk()
            ->assertJsonPath('metrics.0.total', 10);

        $this->record($project, 'sessions', 5, 0);

        // A scoped binding, not a singleton: the next request rebuilds it.
        $this->getJson("/api/projects/{$project->id}/analytics?category=traffic")
            ->assertOk()
            ->assertJsonPath('metrics.0.total', 15);
    }

    public function test_the_cache_is_per_request_not_per_process(): void
    {
        $first = app(SnapshotCache::class);

        // What Laravel does between requests and between queued jobs.
        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(SnapshotCache::class));
    }
}
