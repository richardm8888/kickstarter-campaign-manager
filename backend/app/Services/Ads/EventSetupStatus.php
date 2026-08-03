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

        // The pixel itself may be detectable from the creator's own page.
        $analysis = $project->landingPageAnalyses()->latest()->first();
        $pixelCheck = collect($analysis?->checks ?? [])->firstWhere('key', 'tracking');

        return [
            'meta_connected' => $connected,
            'pixel_detected' => $pixelCheck['passed'] ?? null,
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
