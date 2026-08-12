<?php

namespace App\Recommendations;

/**
 * What to do with an ad. Deliberately a small closed set — the point of
 * the product is to tell a creator what to do, not to present options.
 */
enum AdVerdict: string
{
    case Scale = 'scale';
    case Keep = 'keep';
    case Fix = 'fix';
    case Drop = 'drop';
    case Learning = 'learning';

    /**
     * Not running, so there is nothing to decide. Its own case rather than
     * a flag alongside a verdict, because every consumer reads the verdict
     * and a flag is something each of them has to remember to check.
     */
    case Off = 'off';

    public function label(): string
    {
        return match ($this) {
            self::Scale => 'Scale',
            self::Keep => 'Keep',
            self::Fix => 'Fix',
            self::Drop => 'Drop',
            self::Learning => 'Still learning',
            self::Off => 'Turned off',
        };
    }

    /** Whether this verdict asks the creator to do something. */
    public function isActionable(): bool
    {
        return $this !== self::Off;
    }

    /** Sort order for the UI: act on the extremes first. */
    public function priority(): int
    {
        return match ($this) {
            self::Scale => 0,
            self::Drop => 1,
            self::Fix => 2,
            self::Keep => 3,
            self::Learning => 4,
            self::Off => 5,
        };
    }
}
