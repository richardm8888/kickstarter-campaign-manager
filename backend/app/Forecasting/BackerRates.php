<?php

namespace App\Forecasting;

/**
 * How likely each kind of audience member is to actually back.
 *
 * These three groups behave nothing like each other, and averaging them
 * into a single "list conversion rate" is the most common way a pre-launch
 * forecast goes wrong:
 *
 *   standard email leads   1–3%   someone who gave an address for a lead
 *                                 magnet and may not remember doing so
 *   Kickstarter followers  10–30% Kickstarter emails them the moment the
 *                                 campaign opens, on the platform where
 *                                 they already have a payment method
 *   paid VIP reservers     15–60% they have already paid, which filters
 *                                 for intent better than anything else
 *
 * A follower is therefore worth roughly ten standard leads and a VIP
 * roughly fifteen, which is what makes ad destination — not ad cost —
 * the decision that matters most before launch.
 */
final class BackerRates
{
    public const STANDARD = 'standard';

    public const FOLLOWERS = 'followers';

    public const VIPS = 'vips';

    public const CAUTIOUS = 'cautious';

    public const EXPECTED = 'expected';

    public const OPTIMISTIC = 'optimistic';

    /** @var array<string, array<string, float>> */
    private const SCENARIOS = [
        self::CAUTIOUS => [self::STANDARD => 0.01, self::FOLLOWERS => 0.10, self::VIPS => 0.15],
        self::EXPECTED => [self::STANDARD => 0.02, self::FOLLOWERS => 0.20, self::VIPS => 0.30],
        self::OPTIMISTIC => [self::STANDARD => 0.03, self::FOLLOWERS => 0.30, self::VIPS => 0.60],
    ];

    private const LABELS = [
        self::CAUTIOUS => 'Cautious',
        self::EXPECTED => 'Expected',
        self::OPTIMISTIC => 'Optimistic',
    ];

    private const SEGMENT_LABELS = [
        self::STANDARD => 'Email subscribers',
        self::FOLLOWERS => 'Kickstarter followers',
        self::VIPS => 'Paid VIPs',
    ];

    /**
     * The scenario budgets and targets are built on. The middle of each
     * published range: cautious enough not to flatter a plan, not so
     * pessimistic that it recommends a budget nobody would ever spend.
     */
    public const PLANNING_SCENARIO = self::EXPECTED;

    /** @return array<string, float> */
    public static function scenario(string $name): array
    {
        return self::SCENARIOS[$name] ?? self::SCENARIOS[self::EXPECTED];
    }

    /** @return array<string, float> */
    public static function planning(): array
    {
        return self::scenario(self::PLANNING_SCENARIO);
    }

    /** @return list<string> */
    public static function scenarioNames(): array
    {
        return array_keys(self::SCENARIOS);
    }

    /** @return list<string> */
    public static function segments(): array
    {
        return [self::STANDARD, self::FOLLOWERS, self::VIPS];
    }

    public static function label(string $scenario): string
    {
        return self::LABELS[$scenario] ?? ucfirst($scenario);
    }

    public static function segmentLabel(string $segment): string
    {
        return self::SEGMENT_LABELS[$segment] ?? ucfirst($segment);
    }

    /** The published range for a segment, for showing the spread. */
    public static function range(string $segment): array
    {
        return [
            self::SCENARIOS[self::CAUTIOUS][$segment],
            self::SCENARIOS[self::OPTIMISTIC][$segment],
        ];
    }
}
