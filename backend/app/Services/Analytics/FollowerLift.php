<?php

namespace App\Services\Analytics;

use App\Models\Project;
use Illuminate\Support\Carbon;

/**
 * What each email send did to the Kickstarter follower count.
 *
 * Kickstarter fires no event when somebody follows, and Meta will not
 * hand over pixel event totals — the probe that asked came back
 * "(#100) Permission Denied" on every edge. So no one can be followed
 * from an email to a follow. What can be done is watch the follower
 * count around each send and ask whether it moved more than it does on
 * an ordinary day.
 *
 * That is an inference, not a headcount, and it is described as one
 * everywhere it surfaces. Two things keep it honest:
 *
 *   - Ads buy follows too. Those are reported per day, so they come out
 *     of the window before anything is attributed to the email.
 *   - Followers arrive on quiet days as well. The baseline is what a
 *     quiet day looks like for this project, so only the excess counts.
 *
 * Where either is unavailable the send reports what was observed and
 * says the lift is unknown. A number with nothing behind it would be
 * worse than no number: it would get planned against.
 */
class FollowerLift
{
    /**
     * Days after the send that count as its window, including the send
     * day. Email engagement is overwhelmingly the first 48 hours, and a
     * longer window swallows more of whatever else was happening.
     */
    private const LAG_DAYS = 3;

    /** Quiet days needed before a baseline means anything. */
    private const MINIMUM_BASELINE_DAYS = 5;

    /** How far back to look for quiet days before each send. */
    private const BASELINE_LOOKBACK = 21;

    public function __construct(private readonly MetricSeries $series) {}

    /**
     * @return array{
     *     window_days: int, lag_days: int,
     *     sends: list<array<string, mixed>>,
     *     summary: array<string, mixed>|null,
     *     note: string|null,
     * }
     */
    public function build(Project $project, int $days = 90): array
    {
        $sends = $this->sends($project, $days);
        $followers = $this->levels($project, $days + self::BASELINE_LOOKBACK);
        $adFollows = $this->byDate($project, 'ad_follows', $days + self::BASELINE_LOOKBACK);

        if ($sends === []) {
            return $this->nothing($days, 'No campaigns have been sent in this window.');
        }

        if (count($followers) < 2) {
            return $this->nothing(
                $days,
                'Follower counts are only recorded once your Kickstarter page is linked, and there '
                .'are not yet enough of them to compare one day against another.',
            );
        }

        $sendDates = array_column($sends, 'date');
        $rows = [];

        foreach ($sends as $send) {
            $rows[] = $this->measure($send, $sendDates, $followers, $adFollows);
        }

        return [
            'window_days' => $days,
            'lag_days' => self::LAG_DAYS,
            'sends' => $rows,
            'summary' => $this->summarise($rows),
            'note' => null,
        ];
    }

    /**
     * One send, measured against the days around it.
     *
     * @param  array<string, mixed>  $send
     * @param  list<string>  $sendDates
     * @param  array<string, float>  $followers
     * @param  array<string, float>  $adFollows
     * @return array<string, mixed>
     */
    private function measure(array $send, array $sendDates, array $followers, array $adFollows): array
    {
        $date = Carbon::parse($send['date']);
        $before = $date->copy()->subDay();
        $after = $date->copy()->addDays(self::LAG_DAYS - 1);

        $start = $this->levelOn($followers, $before);
        $end = $this->levelOn($followers, $after);

        $row = [
            'date' => $send['date'],
            'name' => $send['name'],
            'subject' => $send['subject'],
            'recipients' => $send['recipients'],
            'followers_before' => $start,
            'followers_after' => $end,
            'gain' => null,
            'ad_follows' => null,
            'baseline' => null,
            'lift' => null,
            'status' => 'unknown',
            'note' => null,
        ];

        // A send whose window has not finished is not a failure to
        // measure, it is a measurement that has not happened yet, and
        // saying so is the difference between "wait" and "something is
        // wrong".
        if ($after->isFuture()) {
            $row['status'] = 'too_recent';
            $row['note'] = sprintf(
                'Sent %s. Followers are counted over %d days, so this one is still being measured.',
                $date->diffForHumans(),
                self::LAG_DAYS,
            );

            return $row;
        }

        // A follower count on both sides is the minimum. Without it there
        // is nothing to compare, and no amount of arithmetic invents it.
        if ($start === null || $end === null || ! $this->covers($followers, $after)) {
            $row['note'] = 'No follower count recorded on both sides of this send yet.';

            return $row;
        }

        $gain = $end - $start;
        $bought = $this->sumBetween($adFollows, $date, $after);

        $row['gain'] = $gain;
        $row['ad_follows'] = $bought;

        $baseline = $this->baseline($date, $sendDates, $followers, $adFollows);

        if ($baseline === null) {
            $row['status'] = 'no_baseline';
            $row['note'] = 'Not enough quiet days before this send to know what an ordinary day looks like.';

            return $row;
        }

        $expected = $baseline * self::LAG_DAYS;

        $row['baseline'] = round($expected, 1);
        $row['lift'] = round($gain - $bought - $expected, 1);
        $row['status'] = 'measured';

        // Overlapping windows, not merely a send inside this one.
        //
        // An earlier campaign's window reaching into this one contaminates
        // it just as much as a later campaign landing inside it, and the
        // relationship is symmetric: if one is inseparable from the
        // other, both are. Checking only forwards marked the second of a
        // pair as cleanly measured, which is exactly backwards — it is
        // the one sitting in the other's wake.
        $overlapping = array_filter(
            $sendDates,
            fn (string $other) => $other !== $send['date']
                && abs(Carbon::parse($other)->diffInDays($date)) < self::LAG_DAYS,
        );

        if ($overlapping !== []) {
            $row['status'] = 'shared';
            $row['note'] = 'Another campaign went out inside this window, so the two cannot be separated.';
        }

        return $row;
    }

    /**
     * Followers a quiet day adds, for this project, before this send.
     *
     * Quiet means no send in the day's own window, so a previous email's
     * effect cannot become the yardstick the next one is judged against.
     * Ad-bought follows come out of each day for the same reason.
     *
     * The median, not the mean: follower counts arrive in ones and twos
     * with the occasional burst, and one burst would drag a mean far
     * enough to make every later send look like a failure.
     *
     * @param  list<string>  $sendDates
     * @param  array<string, float>  $followers
     * @param  array<string, float>  $adFollows
     */
    private function baseline(Carbon $send, array $sendDates, array $followers, array $adFollows): ?float
    {
        $busy = [];

        foreach ($sendDates as $date) {
            $start = Carbon::parse($date);

            for ($day = 0; $day < self::LAG_DAYS; $day++) {
                $busy[$start->copy()->addDays($day)->toDateString()] = true;
            }
        }

        $gains = [];
        $cursor = $send->copy()->subDay();

        for ($back = 0; $back < self::BASELINE_LOOKBACK; $back++) {
            $day = $cursor->copy()->subDays($back);
            $previous = $day->copy()->subDay();

            if (isset($busy[$day->toDateString()])) {
                continue;
            }

            // Both days must be observed: a gap would otherwise read as
            // a day on which nobody followed.
            if (! $this->covers($followers, $day) || ! $this->covers($followers, $previous)) {
                continue;
            }

            $gains[] = ($followers[$day->toDateString()] - $followers[$previous->toDateString()])
                - ($adFollows[$day->toDateString()] ?? 0.0);
        }

        if (count($gains) < self::MINIMUM_BASELINE_DAYS) {
            return null;
        }

        sort($gains);
        $middle = intdiv(count($gains), 2);

        return count($gains) % 2 === 0
            ? ($gains[$middle - 1] + $gains[$middle]) / 2
            : $gains[$middle];
    }

    /**
     * The totals worth stating, from the sends that could be measured.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function summarise(array $rows): ?array
    {
        $measured = array_values(array_filter($rows, fn (array $row) => $row['status'] === 'measured'));

        if ($measured === []) {
            return null;
        }

        $lift = array_sum(array_column($measured, 'lift'));
        $recipients = array_sum(array_column($measured, 'recipients'));

        return [
            'sends_measured' => count($measured),
            'total_lift' => round($lift, 1),
            'per_send' => round($lift / count($measured), 1),
            // Only where the list size is known, and only as a rate: it
            // is the number worth comparing between campaigns.
            'per_1000_recipients' => $recipients > 0
                ? round($lift / $recipients * 1000, 1)
                : null,
        ];
    }

    /**
     * Sends in the window, newest first, one row per campaign.
     *
     * Read straight rather than through SegmentTotals, which collapses a
     * segment's days into a total — here the date *is* the measurement.
     *
     * @return list<array<string, mixed>>
     */
    private function sends(Project $project, int $days): array
    {
        $rows = $project->metricSnapshots()
            ->where('metric', 'email_campaign_sent')
            ->where('recorded_at', '>=', now()->subDays($days)->startOfDay())
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->toBase()
            ->select('recorded_at', 'value', 'dimensions')
            ->cursor();

        $sends = [];

        foreach ($rows as $row) {
            $dimensions = $row->dimensions === null
                ? []
                : (json_decode((string) $row->dimensions, true) ?: []);

            // Keyed by campaign, so an hourly sync re-reporting the same
            // send updates it rather than counting it again.
            $id = (string) ($dimensions['campaign_id'] ?? $row->recorded_at);

            $sends[$id] = [
                'date' => substr((string) $row->recorded_at, 0, 10),
                'name' => $dimensions['campaign_name'] ?? 'Untitled campaign',
                'subject' => $dimensions['subject'] ?? null,
                'recipients' => (int) $row->value,
            ];
        }

        $sends = array_values($sends);

        usort($sends, fn (array $a, array $b) => strcmp($b['date'], $a['date']));

        return $sends;
    }

    /** @return array<string, float> Followers observed, keyed by date. */
    private function levels(Project $project, int $days): array
    {
        return $this->byDate($project, 'ks_followers', $days);
    }

    /** @return array<string, float> */
    private function byDate(Project $project, string $metric, int $days): array
    {
        return $this->series->daily($project, $metric, $days)
            ->pluck('value', 'date')
            ->all();
    }

    /** @param  array<string, float>  $levels */
    private function covers(array $levels, Carbon $day): bool
    {
        return array_key_exists($day->toDateString(), $levels);
    }

    /**
     * The follower count as at a day — the most recent observation on or
     * before it, since the count is a level and a missing day means
     * nobody looked, not that it dropped to nothing.
     *
     * @param  array<string, float>  $levels
     */
    private function levelOn(array $levels, Carbon $day): ?float
    {
        $wanted = $day->toDateString();
        $best = null;

        foreach ($levels as $date => $value) {
            if ($date <= $wanted && ($best === null || $date > $best[0])) {
                $best = [$date, $value];
            }
        }

        return $best[1] ?? null;
    }

    /** @param  array<string, float>  $byDate */
    private function sumBetween(array $byDate, Carbon $from, Carbon $to): float
    {
        $total = 0.0;

        foreach ($byDate as $date => $value) {
            if ($date >= $from->toDateString() && $date <= $to->toDateString()) {
                $total += $value;
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    private function nothing(int $days, string $note): array
    {
        return [
            'window_days' => $days,
            'lag_days' => self::LAG_DAYS,
            'sends' => [],
            'summary' => null,
            'note' => $note,
        ];
    }
}
