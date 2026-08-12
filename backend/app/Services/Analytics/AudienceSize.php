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
            $project->subscribers()->active()->count(),
            (int) ($this->series->latest($project, 'email_subscribers') ?? 0),
        );
    }

    /**
     * VIPs come from our own £1 upgrade flow and, when a VIP group is
     * configured, from the email provider — whichever knows about more.
     */
    public function vips(Project $project): int
    {
        return max(
            $project->subscribers()->active()->where('is_vip', true)->count(),
            (int) ($this->series->latest($project, 'email_vip_subscribers') ?? 0),
        );
    }

    /**
     * Followers of the Kickstarter pre-launch page.
     *
     * Kickstarter owns these people — we never see an address — so the
     * count comes from the page itself, or from Meta's follow conversions
     * where the page has not been linked yet.
     */
    public function followers(Project $project): int
    {
        return (int) max(
            $this->series->latest($project, 'ks_followers') ?? 0,
            $this->series->sum($project, 'ad_follows', 3650),
        );
    }

    /**
     * Followers the ads did not buy.
     *
     * Meta reports the follows its own ads produced, so subtracting them
     * leaves the ones that came from everything else — the email list,
     * the community, word of mouth. Without this, running Kickstarter-page
     * ads makes an email list look like it is converting brilliantly
     * while the email list does nothing at all.
     *
     * An approximation, and worth being honest about which way it errs:
     * the follower count is a level while ad follows are a running total,
     * so anyone who followed via an ad and later unfollowed is subtracted
     * without ever having been added. That biases this low, which is the
     * safer direction for a figure used to decide whether to nag a list.
     */
    public function organicFollowers(Project $project): int
    {
        $bought = $this->series->sum($project, 'ad_follows', 3650);

        return (int) max(0, $this->followers($project) - $bought);
    }

    /** New contacts in the window, used for conversion and cost per signup. */
    public function recentSignups(Project $project, int $days = 30): int
    {
        return $project->subscribers()
            ->active()
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    /** True when the provider knows about contacts we have no record of. */
    public function hasExternalContacts(Project $project): bool
    {
        return $this->total($project) > $project->subscribers()->active()->count();
    }
}
