<?php

namespace App\Services\Ads;

use App\Models\Integration;
use App\Models\Project;

/**
 * Reports which Meta conversion events are actually arriving, so the setup
 * guide can verify itself instead of just giving instructions.
 */
class EventSetupStatus
{
    public function for(Project $project, int $days = 14): array
    {
        $since = now()->subDays($days)->startOfDay();

        $connected = $project->integrations()
            ->where('provider', 'meta')
            ->where('status', Integration::STATUS_CONNECTED)
            ->exists();

        $viewContent = $this->totals($project, 'ad_view_content', $since);
        $lead = $this->totals($project, 'ad_leads', $since);
        $landingPageViews = $this->totals($project, 'ad_landing_page_views', $since);
        $clicks = $this->totals($project, 'ad_clicks', $since);

        // The pixel itself may be detectable from the creator's own page.
        $analysis = $project->landingPageAnalyses()->latest()->first();
        $pixelCheck = collect($analysis?->checks ?? [])->firstWhere('key', 'tracking');

        return [
            'meta_connected' => $connected,
            'pixel_detected' => $pixelCheck['passed'] ?? null,
            'diagnostics' => $this->diagnose($project, $viewContent, $lead, $landingPageViews, $clicks, $days),
            'events' => [
                [
                    'event' => 'ViewContent',
                    'label' => 'ViewContent',
                    'purpose' => 'Fires when someone lands on your page. Tells you which ads bring real visitors rather than accidental clicks.',
                    'detected' => $viewContent['count'] > 0,
                    'total' => $viewContent['count'],
                    'last_seen' => $viewContent['last_seen'],
                ],
                [
                    'event' => 'Lead',
                    'label' => 'Lead',
                    'purpose' => 'Fires when someone submits their email. This is what ads are judged on — everything else is a proxy.',
                    'detected' => $lead['count'] > 0,
                    'total' => $lead['count'],
                    'last_seen' => $lead['last_seen'],
                ],
            ],
        ];
    }

    /**
     * Meta counts a "landing page view" itself, without needing a pixel
     * event, so it is the yardstick for whether the creator's own
     * ViewContent and Lead tags are firing on every visit.
     *
     * @return list<array{severity: string, title: string, body: string}>
     */
    private function diagnose(
        Project $project,
        array $viewContent,
        array $lead,
        array $landingPageViews,
        array $clicks,
        int $days,
    ): array {
        $findings = [];
        $pageViews = $landingPageViews['count'];

        if ($pageViews > 20 && $viewContent['count'] < $pageViews * 0.5) {
            $percent = (int) round($viewContent['count'] / $pageViews * 100);

            $findings[] = [
                'severity' => 'warning',
                'title' => 'ViewContent is firing on only '.$percent.'% of visits',
                'body' => sprintf(
                    'Meta recorded %d landing page views from your ads but only %d ViewContent events. '
                    .'The tag is probably missing from the page people actually land on, or running before the pixel loads.',
                    $pageViews,
                    $viewContent['count'],
                ),
            ];
        }

        if ($clicks['count'] > 50 && $pageViews > 0 && $pageViews < $clicks['count'] * 0.7) {
            $lost = (int) round((1 - $pageViews / $clicks['count']) * 100);

            $findings[] = [
                'severity' => 'warning',
                'title' => $lost.'% of ad clicks never load your page',
                'body' => sprintf(
                    '%d clicks produced only %d page views. That gap is usually a slow page — you are paying for those clicks either way.',
                    $clicks['count'],
                    $pageViews,
                ),
            ];
        }

        // Signups we recorded ourselves that Meta never attributed to an
        // ad. Deliberately counts people who have since unsubscribed: the
        // question is whether Meta saw the signups that happened, not how
        // many of them are still on the list.
        $ownSignups = $project->subscribers()
            ->where('created_at', '>=', now()->subDays($days))
            ->count();

        if ($ownSignups > 0 && $lead['count'] < $ownSignups * 0.6) {
            $findings[] = [
                'severity' => 'info',
                'title' => 'Meta sees fewer signups than you actually got',
                'body' => sprintf(
                    'You recorded %d signups in %d days; Meta attributed %d to ads. Some of the gap is organic traffic, '
                    .'but browser tracking also loses signups to ad blockers and iOS privacy settings, so ad costs here may look worse than they are.',
                    $ownSignups,
                    $days,
                    $lead['count'],
                ),
            ];
        }

        return $findings;
    }

    /** @return array{count: int, last_seen: ?string} */
    private function totals(Project $project, string $metric, \DateTimeInterface $since): array
    {
        $snapshots = $project->metricSnapshots()
            ->where('metric', $metric)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->get();

        if ($snapshots->isEmpty()) {
            return ['count' => 0, 'last_seen' => null];
        }

        // Repeated syncs restate a day, so take the latest per ad per day.
        $count = (int) $snapshots
            ->groupBy(fn ($s) => ($s->dimensions['ad_id'] ?? 'account').'|'.$s->recorded_at->toDateString())
            ->map(fn ($group) => $group->last()->value)
            ->sum();

        $lastWithValue = $snapshots->last(fn ($s) => $s->value > 0);

        return [
            'count' => $count,
            'last_seen' => $lastWithValue?->recorded_at->toDateString(),
        ];
    }
}
