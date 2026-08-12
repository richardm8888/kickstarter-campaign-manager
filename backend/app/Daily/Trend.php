<?php

namespace App\Daily;

use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * Recent versus established, for one metric.
 *
 * A single day is noise. Almost every wrong marketing decision starts
 * with reacting to one, so nothing here reports a day: the shortest
 * window is three days, compared against the fortnight behind it, and a
 * comparison that has too little data to mean anything reports that it
 * has too little data rather than a number.
 */
final readonly class Trend
{
    private function __construct(
        public float $recent,
        public float $baseline,
        public ?float $changePercent,
        /** Days of the recent window that carried any observation. */
        public int $observedDays,
    ) {}

    /** Not enough history to say anything about direction. */
    public function isEstablished(int $minimumDays = 3): bool
    {
        return $this->changePercent !== null && $this->observedDays >= $minimumDays;
    }

    public function rising(float $byAtLeast = 10.0): bool
    {
        return $this->isEstablished() && $this->changePercent >= $byAtLeast;
    }

    public function falling(float $byAtLeast = 10.0): bool
    {
        return $this->isEstablished() && $this->changePercent <= -$byAtLeast;
    }

    /** Neither rising nor falling by more than the given margin. */
    public function flat(float $within = 10.0): bool
    {
        return $this->isEstablished() && abs($this->changePercent) < $within;
    }

    public function direction(): string
    {
        return match (true) {
            ! $this->isEstablished() => 'unknown',
            $this->changePercent >= 5 => 'up',
            $this->changePercent <= -5 => 'down',
            default => 'flat',
        };
    }

    /**
     * Compares the last $recentDays against the $baselineDays before them.
     *
     * Level metrics (list size, CPC) are averaged, because summing a
     * running total across days is meaningless; everything else is summed,
     * because a week of signups is the week's signups.
     */
    public static function of(
        MetricSeries $series,
        Project $project,
        string $metric,
        int $recentDays = 7,
        int $baselineDays = 21,
    ): self {
        $daily = $series->daily($project, $metric, $recentDays + $baselineDays)
            ->keyBy('date')
            ->map(fn (array $point) => (float) $point['value']);

        $recentFrom = now()->subDays($recentDays)->startOfDay();

        $recent = [];
        $baseline = [];

        foreach ($daily as $date => $value) {
            if (\Illuminate\Support\Carbon::parse($date)->gte($recentFrom)) {
                $recent[] = $value;
            } else {
                $baseline[] = $value;
            }
        }

        $level = app(\App\Services\Analytics\MetricCatalog::class)->isLevel($metric);

        $recentValue = self::combine($recent, $level);
        $baselineValue = self::combine($baseline, $level);

        // A baseline of zero has no percentage — going from nothing to
        // something is real, but it is not "up 400%", and saying so would
        // rank a trickle above a genuine problem.
        $change = ($baseline === [] || $baselineValue == 0.0)
            ? null
            : round((($recentValue - $baselineValue) / $baselineValue) * 100, 1);

        return new self($recentValue, $baselineValue, $change, count($recent));
    }

    /** @param  list<float>  $values */
    private static function combine(array $values, bool $level): float
    {
        if ($values === []) {
            return 0.0;
        }

        return $level ? array_sum($values) / count($values) : array_sum($values);
    }
}
