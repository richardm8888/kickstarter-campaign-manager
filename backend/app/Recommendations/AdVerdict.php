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

    public function label(): string
    {
        return match ($this) {
            self::Scale => 'Scale',
            self::Keep => 'Keep',
            self::Fix => 'Fix',
            self::Drop => 'Drop',
            self::Learning => 'Still learning',
        };
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
        };
    }
}
