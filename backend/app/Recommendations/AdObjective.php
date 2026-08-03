<?php

namespace App\Recommendations;

/**
 * What Meta was told to optimise an ad for.
 *
 * This matters because an ad optimised for landing page views is doing
 * exactly what it was asked when it buys cheap visits that never convert.
 * Judging it on cost per signup would condemn it for the wrong reason —
 * the fault is the objective, not the creative.
 */
enum AdObjective: string
{
    case Signups = 'signups';
    case Traffic = 'traffic';
    case Other = 'other';

    private const SIGNUP_GOALS = [
        'LEAD_GENERATION', 'QUALITY_LEAD', 'OFFSITE_CONVERSIONS', 'CONVERSIONS',
        'OUTCOME_LEADS', 'OUTCOME_SALES',
    ];

    private const TRAFFIC_GOALS = [
        'LANDING_PAGE_VIEWS', 'LINK_CLICKS', 'QUALITY_CALL',
        'OUTCOME_TRAFFIC', 'TRAFFIC',
    ];

    /** Optimisation goal is the precise signal; campaign objective is the fallback. */
    public static function classify(?string $optimizationGoal, ?string $objective): self
    {
        foreach ([$optimizationGoal, $objective] as $value) {
            $value = strtoupper((string) $value);

            if ($value === '') {
                continue;
            }

            if (in_array($value, self::SIGNUP_GOALS, true)) {
                return self::Signups;
            }

            if (in_array($value, self::TRAFFIC_GOALS, true)) {
                return self::Traffic;
            }
        }

        return self::Other;
    }

    public function label(): string
    {
        return match ($this) {
            self::Signups => 'Optimised for signups',
            self::Traffic => 'Optimised for landing page views',
            self::Other => 'Other objective',
        };
    }
}
