<?php

namespace App\Daily\Detectors;

use App\Daily\Detector;
use App\Daily\Signal;
use App\Daily\Trend;
use App\Models\DailyTask;
use App\Models\Project;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;

/**
 * Whether the list is still listening.
 *
 * A pre-launch list is only worth what it will do on launch day, and the
 * thing that quietly destroys that is silence. People who have not heard
 * from a project in two months do not remember signing up, and an
 * unsubscribe rate is a much later, much louder version of the same
 * message.
 */
class EmailEngagementDetector implements Detector
{
    /** Below this, a send is landing but nobody is reading. */
    private const WEAK_OPEN_RATE = 0.20;

    /** Above this, the list is actively rejecting what it is being sent. */
    private const HIGH_UNSUBSCRIBE_RATE = 0.02;

    /** A list this small has nothing statistically meaningful to say. */
    private const MINIMUM_LIST = 30;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
    ) {}

    /** @return list<Signal> */
    public function detect(Project $project): array
    {
        return array_values(array_filter([
            $this->listGoingCold($project),
            $this->peopleLeaving($project),
        ]));
    }

    /**
     * A list that is being collected and never contacted. The most
     * common pre-launch mistake, and the cheapest to fix.
     */
    private function listGoingCold(Project $project): ?Signal
    {
        $subscribers = $this->audience->total($project);

        if ($subscribers < self::MINIMUM_LIST) {
            return null;
        }

        // Opens are recorded against the day a campaign went out, so no
        // opens across a month means no campaign went out in it.
        $sentRecently = $this->series->sum($project, 'email_opens', 30) > 0;

        if ($sentRecently) {
            return null;
        }

        return new Signal(
            key: 'email_list_going_cold',
            title: sprintf('Email your %s subscribers — nothing has gone out in a month', number_format($subscribers)),
            why: 'No campaign has been opened in the last 30 days, so the list has heard nothing. A list that goes quiet before launch does not come back on launch day; people forget they ever signed up.',
            action: 'Send one short update: what you have been building, one image, and a line asking them to follow the Kickstarter page. Do not wait until you have something impressive.',
            effortMinutes: 30,
            impact: DailyTask::HIGH,
            confidence: 0.8,
            urgency: 0.6,
            evidence: ['subscribers' => $subscribers, 'days_since_send' => 30],
        );
    }

    /** Sends going out and people leaving faster than they should. */
    private function peopleLeaving(Project $project): ?Signal
    {
        $unsubscribes = Trend::of($this->series, $project, 'email_unsubscribes', 14, 14);
        $subscribers = $this->audience->total($project);

        if ($subscribers < self::MINIMUM_LIST || $unsubscribes->recent <= 0) {
            return null;
        }

        $rate = $unsubscribes->recent / $subscribers;

        if ($rate < self::HIGH_UNSUBSCRIBE_RATE) {
            return null;
        }

        return new Signal(
            key: 'email_unsubscribes_high',
            title: 'People are leaving the list faster than they should',
            why: sprintf(
                '%s unsubscribes in the last fortnight against a list of %s (%s%%). Above about 2%% usually means the emails are not what people expected when they signed up.',
                (int) $unsubscribes->recent,
                number_format($subscribers),
                round($rate * 100, 1),
            ),
            action: 'Read your last send as though you had just joined. If it is about you rather than about the game they signed up for, change the next one.',
            effortMinutes: 15,
            impact: DailyTask::MEDIUM,
            confidence: 0.7,
            urgency: 0.5,
            evidence: [
                'unsubscribes' => (int) $unsubscribes->recent,
                'subscribers' => $subscribers,
                'rate' => round($rate * 100, 1),
            ],
        );
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        $openRate = $this->series->latest($project, 'email_form_open_rate')
            ?? $this->series->latest($project, 'email_other_open_rate');

        if ($openRate !== null && $openRate >= self::WEAK_OPEN_RATE) {
            return [sprintf('Email engagement is healthy at a %s%% open rate.', round($openRate * 100))];
        }

        return [];
    }
}
