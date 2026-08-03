<?php

namespace App\Forecasting;

/**
 * Everything the forecast engine needs, expressed as plain values so the
 * engine stays deterministic and unit-testable. Money is in minor units.
 */
final readonly class ForecastInput
{
    public function __construct(
        public int $emailSubscribers,
        public int $vipCount,
        public int $plannedAdSpend,
        public float $cpc,
        public float $visitorToSubscriberRate,
        public float $subscriberToBackerRate,
        public float $vipToBackerRate,
        public int $averagePledge,
        public int $fundingGoal,
        /** How many of the inputs above came from observed data (0–1). */
        public float $dataCompleteness = 0.0,
    ) {}

    /**
     * Opinionated pre-launch benchmarks, used when a project has no
     * observed data yet. Sources: typical crowdfunding pre-launch funnels.
     */
    public static function defaults(): array
    {
        return [
            'cpc' => 0.85,
            'visitor_to_subscriber_rate' => 0.22,
            // The most cautious of the scenarios, so nothing built on this
            // default — including how much an ad may spend per signup —
            // assumes a better outcome than the floor.
            'subscriber_to_backer_rate' => 0.10,
            'vip_to_backer_rate' => 0.30,
        ];
    }
}
