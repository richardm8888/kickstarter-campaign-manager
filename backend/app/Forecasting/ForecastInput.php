<?php

namespace App\Forecasting;

/**
 * Everything the forecast engine needs, expressed as plain values so the
 * engine stays deterministic and unit-testable. Money is in minor units.
 */
final readonly class ForecastInput
{
    /**
     * @param  array<string, float>  $backerRates  per-segment rates, see BackerRates
     * @param  array{standard: float, followers: float, vips: float}|null  $adMix
     *                                                                            share of new signups each segment takes, measured from the ads
     */
    public function __construct(
        public AudienceMix $audience,
        public int $plannedAdSpend,
        public float $cpc,
        public float $visitorToSubscriberRate,
        public array $backerRates,
        public int $averagePledge,
        public int $fundingGoal,
        /** How many of the inputs above came from observed data (0–1). */
        public float $dataCompleteness = 0.0,
        public ?array $adMix = null,
    ) {}

    /** The owned email list, VIPs included. */
    public function emailSubscribers(): int
    {
        return $this->audience->standard + $this->audience->vips;
    }

    public function vipCount(): int
    {
        return $this->audience->vips;
    }

    public function followers(): int
    {
        return $this->audience->followers;
    }

    /**
     * What the next signup is worth, on average, given where this project's
     * ads actually send people. Ads that produce Kickstarter followers lift
     * this sharply, which is the point.
     */
    public function marginalBackerRate(): float
    {
        $mix = $this->adMix ?? [BackerRates::STANDARD => 1.0];

        $rate = 0.0;

        foreach (BackerRates::segments() as $segment) {
            $rate += ($mix[$segment] ?? 0.0) * ($this->backerRates[$segment] ?? 0.0);
        }

        return $rate;
    }

    /**
     * Opinionated pre-launch benchmarks, used when a project has no
     * observed data yet. Sources: typical crowdfunding pre-launch funnels.
     */
    public static function defaults(): array
    {
        return [
            'cpc' => 0.85,
            'visitor_to_subscriber_rate' => 0.22,
        ];
    }
}
