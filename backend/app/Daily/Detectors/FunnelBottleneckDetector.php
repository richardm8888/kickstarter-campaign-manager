<?php

namespace App\Daily\Detectors;

use App\Daily\Detector;
use App\Daily\Signal;
use App\Daily\Trend;
use App\Models\DailyTask;
use App\Models\Project;
use App\Services\Analytics\AudienceSize;
use App\Services\Analytics\MetricSeries;
use App\Services\Analytics\SegmentTotals;

/**
 * Finds the stage that is holding everything else back.
 *
 * Traffic → signups → Kickstarter followers → backers. Each stage can
 * only pass on what the one before it delivers, so the useful question is
 * never "is this number good" but "which stage is losing the most people
 * that the stage before it earned".
 *
 * The reason this matters more than any single metric: the obvious
 * response to a disappointing number is usually to buy more of the thing
 * in front of it. If traffic is rising and signups are not, more traffic
 * is the one action guaranteed not to help.
 */
class FunnelBottleneckDetector implements Detector
{
    /**
     * Below this, a list is producing few followers for its size.
     *
     * Deliberately a ratio and not a conversion rate. Nothing links the
     * two populations: Kickstarter hands over a follower total and no
     * identities, so we can never learn whether a follower was ever on
     * the list. What we can say is that a large list sitting beside very
     * few unbought followers is worth a nudge, and that is all this claims.
     */
    private const WEAK_FOLLOWERS_PER_SUBSCRIBER = 0.25;

    /** A landing page that converts below this is losing bought traffic. */
    private const WEAK_VISITOR_TO_SIGNUP = 0.15;

    /** Too few people through a stage for its rate to mean anything. */
    private const MINIMUM_SAMPLE = 25;

    /** GA4's word for traffic that came from an email tool. */
    private const EMAIL_SOURCES = ['mailerlite', 'email', 'newsletter', 'ml'];

    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
        private readonly SegmentTotals $segments,
    ) {}

    /** @return list<Signal> */
    public function detect(Project $project): array
    {
        return array_values(array_filter([
            $this->trafficNotConverting($project),
            $this->listNotFollowing($project),
        ]));
    }

    /**
     * Visitors arriving and not signing up.
     *
     * Only raised when traffic is actually rising: a flat page converting
     * badly is a page problem worth fixing eventually, while a page
     * quietly wasting money that is being spent right now is today's job.
     */
    private function trafficNotConverting(Project $project): ?Signal
    {
        $visits = Trend::of($this->series, $project, 'ad_landing_page_views');
        $signups = Trend::of($this->series, $project, 'ad_leads');

        if (! $visits->isEstablished() || $visits->recent < self::MINIMUM_SAMPLE) {
            return null;
        }

        $rate = $visits->recent > 0 ? $signups->recent / $visits->recent : 0.0;

        // Rising traffic with flat or falling signups is the clearest
        // possible statement that the page, not the budget, is the limit.
        $diverging = $visits->rising(15) && ! $signups->rising(5);

        if (! $diverging && $rate >= self::WEAK_VISITOR_TO_SIGNUP) {
            return null;
        }

        $percent = round($rate * 100, 1);

        return new Signal(
            key: 'bottleneck_landing_page',
            title: 'Fix the landing page before buying more traffic',
            why: $diverging
                ? sprintf(
                    'Landing page views are up %s%% over the last week but signups are not following (%s of %s visitors, %s%%). More budget would buy more of the same result.',
                    abs((int) $visits->changePercent),
                    (int) $signups->recent,
                    (int) $visits->recent,
                    $percent,
                )
                : sprintf(
                    '%s of %s visitors signed up in the last week (%s%%). Every extra pound of spend is being taxed by the page.',
                    (int) $signups->recent,
                    (int) $visits->recent,
                    $percent,
                ),
            action: 'Run the landing page analyser and fix its heaviest failing check — usually a headline that does not say what the game is, or a form asking for more than an email address.',
            effortMinutes: 30,
            impact: DailyTask::HIGH,
            confidence: $visits->recent >= 100 ? 0.85 : 0.6,
            urgency: $diverging ? 0.8 : 0.5,
            evidence: [
                'landing_page_views' => (int) $visits->recent,
                'signups' => (int) $signups->recent,
                'conversion' => $percent,
                'views_change_percent' => $visits->changePercent,
            ],
        );
    }

    /**
     * A list that is not becoming followers.
     *
     * This is the most valuable conversion in a pre-launch campaign and
     * the one most often left alone, because nothing breaks when it is
     * ignored — the list still grows, the numbers still rise, and the
     * launch-day audience is quietly a tenth of what it looks like.
     */
    private function listNotFollowing(Project $project): ?Signal
    {
        if ($project->kickstarter_url === null) {
            return null;
        }

        $subscribers = $this->audience->total($project);

        // Follows the ads bought are not the list's doing, and counting
        // them here would let paid follows silence advice about an email
        // list that is doing nothing.
        $followers = $this->audience->organicFollowers($project);

        if ($subscribers < self::MINIMUM_SAMPLE) {
            return null;
        }

        $ratio = $followers / $subscribers;

        if ($ratio >= self::WEAK_FOLLOWERS_PER_SUBSCRIBER) {
            return null;
        }

        // Growing the list while this stays broken widens the gap rather
        // than closing it, which is why urgency tracks list growth.
        $listGrowth = Trend::of($this->series, $project, 'email_subscribers');
        $stillGrowing = $listGrowth->rising(5);

        // Where it can be measured, say which half is broken. Nobody
        // arriving means the emails are not asking; plenty arriving and
        // few following means the page is not persuading, and those want
        // completely different mornings.
        $arrivals = $this->emailArrivals($project);

        return new Signal(
            key: 'bottleneck_email_to_follower',
            title: 'Ask your email list to follow the Kickstarter page',
            why: sprintf(
                'You have %s subscribers and %s followers your ads did not buy — %s per hundred. A follower backs at roughly ten times the rate of a plain subscriber, so a list this size producing so few is the biggest gap in the funnel.%s',
                number_format($subscribers),
                number_format($followers),
                round($ratio * 100),
                $stillGrowing ? ' The list is still growing, so the gap is widening.' : '',
            ).$this->arrivalNote($arrivals),
            action: 'Send one short email asking them to follow the pre-launch page, saying plainly that followers get told the moment it opens and that launch-day backers decide whether Kickstarter shows the project to anyone else.',
            effortMinutes: 20,
            impact: DailyTask::HIGH,
            confidence: 0.9,
            urgency: $stillGrowing ? 0.85 : 0.65,
            evidence: [
                'subscribers' => $subscribers,
                'organic_followers' => $followers,
                'followers_per_hundred' => round($ratio * 100, 1),
                'list_growing' => $stillGrowing,
                'email_arrivals_at_kickstarter' => $arrivals,
            ],
        );
    }

    /**
     * Sessions on the Kickstarter page that came from an email tool, over
     * the last month. Null when the project's Google Analytics ID is not
     * set in Kickstarter's project settings, which is the only way this
     * can be seen at all.
     */
    private function emailArrivals(Project $project): ?int
    {
        $rows = $this->segments->get(
            $project,
            ['ks_page_sessions_by_source'],
            'source',
            30,
            'ga4',
        );

        if ($rows === []) {
            return null;
        }

        $fromEmail = 0.0;

        foreach ($rows as $row) {
            $source = strtolower((string) $row['dimensions']['source']);

            foreach (self::EMAIL_SOURCES as $needle) {
                if (str_contains($source, $needle)) {
                    $fromEmail += $row['totals']['ks_page_sessions_by_source'];

                    break;
                }
            }
        }

        return (int) $fromEmail;
    }

    private function arrivalNote(?int $arrivals): string
    {
        return match (true) {
            $arrivals === null => '',
            $arrivals === 0 => ' Nothing has reached the Kickstarter page from your emails in the last month, so the asking is what is missing rather than the page.',
            default => sprintf(
                ' %s people reached the page from your emails in the last month, so the link is being clicked — following is where they stop.',
                number_format($arrivals),
            ),
        };
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        $notes = [];

        $subscribers = $this->audience->total($project);
        $followers = $this->audience->organicFollowers($project);

        if ($subscribers >= self::MINIMUM_SAMPLE
            && $followers / $subscribers >= self::WEAK_FOLLOWERS_PER_SUBSCRIBER) {
            // Stated as a ratio, because that is what it is. Nothing ties
            // a follower to a subscriber, so calling it a conversion rate
            // would claim a measurement nobody has.
            $notes[] = sprintf(
                '%s followers against %s subscribers — a healthy ratio for a pre-launch list.',
                number_format($followers),
                number_format($subscribers),
            );
        }

        $visits = Trend::of($this->series, $project, 'ad_landing_page_views');
        $signups = Trend::of($this->series, $project, 'ad_leads');

        if ($visits->recent >= self::MINIMUM_SAMPLE
            && $signups->recent / max($visits->recent, 1) >= self::WEAK_VISITOR_TO_SIGNUP) {
            $notes[] = 'The landing page is converting bought traffic at a healthy rate.';
        }

        return $notes;
    }
}
