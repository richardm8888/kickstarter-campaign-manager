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
     * Below this, an email list is barely converting into the audience
     * that actually backs. Followers back at around 20% against 2% for a
     * plain subscriber, so this conversion is worth more than any other
     * in the funnel.
     */
    private const WEAK_EMAIL_TO_FOLLOWER = 0.25;

    /** A landing page that converts below this is losing bought traffic. */
    private const WEAK_VISITOR_TO_SIGNUP = 0.15;

    /** Too few people through a stage for its rate to mean anything. */
    private const MINIMUM_SAMPLE = 25;

    public function __construct(
        private readonly MetricSeries $series,
        private readonly AudienceSize $audience,
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
        $followers = $this->audience->followers($project);

        if ($subscribers < self::MINIMUM_SAMPLE) {
            return null;
        }

        $rate = $followers / $subscribers;

        if ($rate >= self::WEAK_EMAIL_TO_FOLLOWER) {
            return null;
        }

        // Growing the list while this stays broken widens the gap rather
        // than closing it, which is why urgency tracks list growth.
        $listGrowth = Trend::of($this->series, $project, 'email_subscribers');
        $stillGrowing = $listGrowth->rising(5);

        return new Signal(
            key: 'bottleneck_email_to_follower',
            title: 'Ask your email list to follow the Kickstarter page',
            why: sprintf(
                '%s subscribers have produced %s followers (%s%%). A follower backs at roughly ten times the rate of a plain subscriber, so this gap is costing more than any other in the funnel.%s',
                $subscribers,
                $followers,
                round($rate * 100, 1),
                $stillGrowing ? ' The list is still growing, so the gap is widening.' : '',
            ),
            action: 'Send one short email asking them to follow the pre-launch page, saying plainly that followers get told the moment it opens and that launch-day backers decide whether Kickstarter shows the project to anyone else.',
            effortMinutes: 20,
            impact: DailyTask::HIGH,
            confidence: 0.9,
            urgency: $stillGrowing ? 0.85 : 0.65,
            evidence: [
                'subscribers' => $subscribers,
                'followers' => $followers,
                'conversion' => round($rate * 100, 1),
                'list_growing' => $stillGrowing,
            ],
        );
    }

    /** @return list<string> */
    public function reassurances(Project $project): array
    {
        $notes = [];

        $subscribers = $this->audience->total($project);
        $followers = $this->audience->followers($project);

        if ($subscribers >= self::MINIMUM_SAMPLE && $followers / $subscribers >= self::WEAK_EMAIL_TO_FOLLOWER) {
            $notes[] = sprintf(
                'Email to Kickstarter follower is converting at %s%% — above what most pre-launch campaigns manage.',
                round($followers / $subscribers * 100, 1),
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
