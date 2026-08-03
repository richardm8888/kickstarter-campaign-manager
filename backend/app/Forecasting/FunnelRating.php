<?php

namespace App\Forecasting;

/**
 * Judges the two numbers a pre-launch funnel turns on, so the forecast
 * points at the thing worth fixing rather than presenting figures flatly.
 *
 * Thresholds are absolute, not relative to the account: a creator with one
 * cheap ad and a page nobody converts on should be told the page is the
 * problem, not congratulated for being consistent.
 */
class FunnelRating
{
    public const GOOD = 'good';
    public const WARNING = 'warning';
    public const DANGER = 'danger';

    /** Cost per click: cheap traffic is table stakes, expensive traffic kills a campaign. */
    private const CPC_WARNING = 1.00;
    private const CPC_DANGER = 1.50;

    /** Visitors who join the list. Below 4% the page, not the ads, is the problem. */
    private const CONVERSION_GOOD = 0.10;
    private const CONVERSION_DANGER = 0.04;

    public function cpc(float $cpc): array
    {
        $rating = match (true) {
            $cpc > self::CPC_DANGER => self::DANGER,
            $cpc > self::CPC_WARNING => self::WARNING,
            default => self::GOOD,
        };

        return [
            'rating' => $rating,
            'note' => match ($rating) {
                self::DANGER => 'Over £1.50 a click, ads cannot pay for themselves. Test new creative and narrow the audience.',
                self::WARNING => 'Above £1 a click. Worth testing fresh creative before spending more.',
                default => 'Cheap traffic — your ads are doing their job.',
            },
        ];
    }

    public function conversion(float $rate): array
    {
        $rating = match (true) {
            $rate < self::CONVERSION_DANGER => self::DANGER,
            $rate < self::CONVERSION_GOOD => self::WARNING,
            default => self::GOOD,
        };

        return [
            'rating' => $rating,
            'note' => match ($rating) {
                self::DANGER => 'Under 4% of visitors subscribe. Something on the page is losing almost everyone who arrives.',
                self::WARNING => 'Under 10% of visitors subscribe. There is real room here.',
                default => 'A healthy share of visitors join your list.',
            },
        ];
    }

    /**
     * The single thing worth working on next. Conversion outranks cost
     * because a page that does not convert wastes every click, however
     * cheap — and cheap clicks make fixing the page the better investment.
     */
    public function focus(float $cpc, float $rate, bool $measured): ?array
    {
        if (! $measured) {
            return [
                'severity' => 'info',
                'title' => 'Run some ads before trusting this',
                'body' => 'These figures are pre-launch benchmarks, not yours. A small test campaign replaces them with real numbers within a day or two.',
                'action' => 'Start the instant form campaign with a small daily budget.',
            ];
        }

        $conversion = $this->conversion($rate);
        $click = $this->cpc($cpc);

        if ($conversion['rating'] !== self::GOOD) {
            $cheapClicks = $click['rating'] === self::GOOD;

            return [
                'severity' => $conversion['rating'] === self::DANGER ? 'critical' : 'warning',
                'title' => sprintf('Only %s of visitors join your list', $this->percent($rate)),
                'body' => $cheapClicks
                    ? sprintf(
                        'Clicks cost just %s, so traffic is not your problem — the page is. Every point of conversion you gain here is worth more than any ad change.',
                        $this->money($cpc),
                    )
                    : 'Both your traffic cost and your conversion need work, but fix conversion first: it makes every click worth more.',
                'action' => 'Cut the page back to one promise and one email field, and put the form above the fold.',
            ];
        }

        if ($click['rating'] !== self::GOOD) {
            return [
                'severity' => $click['rating'] === self::DANGER ? 'critical' : 'warning',
                'title' => sprintf('Clicks cost %s', $this->money($cpc)),
                'body' => 'Your page converts well, so the cost of getting people to it is what limits you.',
                'action' => 'Refresh the creative and tighten the audience.',
            ];
        }

        return null;
    }

    private function money(float $amount): string
    {
        return '£'.number_format($amount, 2);
    }

    private function percent(float $rate): string
    {
        return round($rate * 100, 1).'%';
    }
}
