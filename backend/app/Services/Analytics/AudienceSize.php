<?php

namespace App\Services\Analytics;

use App\Models\Project;

/**
 * How big the email list actually is.
 *
 * Contacts arrive from two directions: people captured through our own
 * pages and Meta lead imports (rows in `subscribers`), and everyone
 * already in the email provider (reported by its sync). Counting only the
 * former understates a list that was built before this tool existed, so
 * the provider's figure wins whenever it is larger.
 */
class AudienceSize
{
    public function __construct(private readonly MetricSeries $series) {}

    public function total(Project $project): int
    {
        return max(
            $project->subscribers()->count(),
            (int) ($this->series->latest($project, 'email_subscribers') ?? 0),
        );
    }

    public function vips(Project $project): int
    {
        return $project->subscribers()->where('is_vip', true)->count();
    }

    /** New contacts in the window, used for conversion and cost per signup. */
    public function recentSignups(Project $project, int $days = 30): int
    {
        return $project->subscribers()
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    /** True when the provider knows about contacts we have no record of. */
    public function hasExternalContacts(Project $project): bool
    {
        return $this->total($project) > $project->subscribers()->count();
    }
}
