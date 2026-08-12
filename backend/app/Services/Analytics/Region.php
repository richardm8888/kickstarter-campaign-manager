<?php

namespace App\Services\Analytics;

/**
 * Where a visitor came from, at the only granularity that changes a
 * decision.
 *
 * Three buckets, not two hundred countries. A tabletop creator makes
 * different choices for each: the UK ships cheaply and is usually the
 * home market, the EU carries customs paperwork and VAT since Brexit that
 * can swallow a campaign's margin, and everywhere else is mostly the US —
 * the largest Kickstarter market there is, and the most expensive to post
 * a boxed game to.
 */
enum Region: string
{
    case Uk = 'uk';
    case Eu = 'eu';
    case International = 'international';

    /** ISO 3166-1 alpha-2, because country names vary by locale. */
    private const EU = [
        'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE',
        'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT',
        'RO', 'SK', 'SI', 'ES', 'SE',
    ];

    public static function forCountry(?string $isoCode): self
    {
        $code = strtoupper(trim((string) $isoCode));

        return match (true) {
            $code === 'GB' => self::Uk,
            in_array($code, self::EU, true) => self::Eu,
            // Includes GA4's "(not set)", which is a real share of traffic
            // and belongs somewhere rather than being dropped.
            default => self::International,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Uk => 'UK',
            self::Eu => 'EU',
            self::International => 'Rest of world',
        };
    }

    /** @return list<self> in the order they are shown. */
    public static function ordered(): array
    {
        return [self::Uk, self::Eu, self::International];
    }
}
