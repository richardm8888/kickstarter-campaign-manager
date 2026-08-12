<?php

namespace App\Services\Analytics;

use App\Models\Project;

/**
 * Sessions against signups, split by where people came from and where
 * they are.
 *
 * A single site-wide conversion rate hides the only interesting thing
 * about it: traffic that converts at 8% and traffic that converts at 0.3%
 * average out to something that looks merely mediocre. Split by referrer,
 * the difference is a decision about where the next pound goes.
 *
 * Region matters for a different reason. Shipping a boxed game to the US
 * costs multiples of shipping it inside the UK, and EU orders have
 * carried customs paperwork since Brexit, so a cheap signup from the
 * wrong place is not necessarily a cheap backer.
 */
class ConversionBreakdown
{
    /** Below this a percentage is noise dressed up as a finding. */
    private const MINIMUM_SESSIONS = 20;

    public function __construct(private readonly SegmentTotals $segments) {}

    /** @return array<string, mixed> */
    public function build(Project $project, int $days = 30): array
    {
        return [
            'by_source' => $this->bySource($project, $days),
            'by_region' => $this->byRegion($project, $days),
            'kickstarter_arrivals' => $this->kickstarterArrivals($project, $days),
        ];
    }

    /**
     * Referrers, busiest first, with the long tail folded away — a table
     * with forty rows of one session each is not a breakdown.
     *
     * @return list<array<string, mixed>>
     */
    private function bySource(Project $project, int $days): array
    {
        $rows = [];

        foreach ($this->segments->get(
            $project,
            ['sessions_by_source', 'leads_by_source'],
            'source',
            $days,
            'ga4',
        ) as $segment) {
            $rows[] = $this->row(
                (string) $segment['dimensions']['source'],
                (string) $segment['dimensions']['source'],
                $segment['totals']['sessions_by_source'],
                $segment['totals']['leads_by_source'],
            );
        }

        usort($rows, fn (array $a, array $b) => $b['sessions'] <=> $a['sessions']);

        return array_slice($rows, 0, 8);
    }

    /**
     * Always all three regions, in the same order, including empty ones.
     * A missing row reads as "no data"; a zero reads as "nobody", and
     * those mean different things when deciding where to advertise.
     *
     * @return list<array<string, mixed>>
     */
    private function byRegion(Project $project, int $days): array
    {
        $found = [];

        foreach ($this->segments->get(
            $project,
            ['sessions_by_region', 'leads_by_region'],
            'region',
            $days,
            'ga4',
        ) as $segment) {
            $found[(string) $segment['dimensions']['region']] = $segment['totals'];
        }

        return array_map(function (Region $region) use ($found) {
            $totals = $found[$region->value] ?? [];

            return $this->row(
                $region->value,
                $region->label(),
                $totals['sessions_by_region'] ?? 0.0,
                $totals['leads_by_region'] ?? 0.0,
            );
        }, Region::ordered());
    }

    /**
     * Who reached the Kickstarter page, and from where.
     *
     * This is the end of what can be measured. Kickstarter fires no event
     * when somebody follows, so the last step of the funnel is invisible
     * however the tracking is set up — an arrival is not a follow, and
     * saying otherwise would be inventing the number that matters most.
     *
     * @return list<array<string, mixed>>
     */
    private function kickstarterArrivals(Project $project, int $days): array
    {
        $rows = [];

        foreach ($this->segments->get(
            $project,
            ['ks_page_sessions_by_source'],
            'source',
            $days,
            'ga4',
        ) as $segment) {
            $rows[] = [
                'key' => (string) $segment['dimensions']['source'],
                'label' => (string) $segment['dimensions']['source'],
                'sessions' => (int) $segment['totals']['ks_page_sessions_by_source'],
                'leads' => 0,
                'conversion' => null,
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['sessions'] <=> $a['sessions']);

        return array_slice($rows, 0, 8);
    }

    /** @return array<string, mixed> */
    private function row(string $key, string $label, float $sessions, float $leads): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'sessions' => (int) $sessions,
            'leads' => (int) $leads,
            // Null rather than zero below the threshold: "we cannot say"
            // and "nobody converted" look identical as 0% and are not.
            'conversion' => $sessions >= self::MINIMUM_SESSIONS
                ? round($leads / $sessions * 100, 1)
                : null,
        ];
    }
}
