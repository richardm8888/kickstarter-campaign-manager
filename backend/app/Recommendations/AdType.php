<?php

namespace App\Recommendations;

/**
 * The three ads a pre-launch campaign should run, distinguished by where
 * they send people.
 *
 * This matters because the same Meta event means different things
 * depending on destination: a pixel "Lead" on a Kickstarter page is a
 * follow Kickstarter owns, while the identical event on the creator's own
 * page is an email address they own.
 */
enum AdType: string
{
    case Kickstarter = 'kickstarter';
    case LandingPage = 'landing_page';
    case InstantForm = 'instant_form';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Kickstarter => 'Kickstarter page',
            self::LandingPage => 'Landing page',
            self::InstantForm => 'Instant form',
            self::Unknown => 'Unclassified',
        };
    }

    /** What a conversion from this ad actually gets the creator. */
    public function conversionLabel(): string
    {
        return match ($this) {
            self::Kickstarter => 'follows',
            default => 'signups',
        };
    }

    /** Whether a pixel Lead from this ad is a follow rather than a contact. */
    public function pixelLeadIsFollow(): bool
    {
        return $this === self::Kickstarter;
    }

    /**
     * Whether a click on this ad could ever load a page carrying our
     * pixel. An instant form opens inside Meta and never leaves it; a
     * Kickstarter ad loads a page we cannot tag.
     *
     * Unclassified counts as yes. We reach here when a creative could not
     * be read, and staying silent about an ad we failed to categorise
     * would turn a missing field into a missing diagnostic — the exact
     * failure the tracking checks exist to catch.
     */
    public function canLoadOurPage(): bool
    {
        return match ($this) {
            self::InstantForm, self::Kickstarter => false,
            default => true,
        };
    }

    /** Derived from an ad's creative: its form, or the link it points at. */
    public static function fromCreative(array $creative): self
    {
        $json = json_encode($creative) ?: '';

        if (str_contains($json, 'lead_gen_form_id')) {
            return self::InstantForm;
        }

        foreach (self::links($creative) as $link) {
            if (str_contains(strtolower($link), 'kickstarter.com')) {
                return self::Kickstarter;
            }
        }

        return self::links($creative) !== [] ? self::LandingPage : self::Unknown;
    }

    /**
     * Creative shapes vary by ad format, so collect every link rather than
     * reaching for one known path.
     *
     * @return list<string>
     */
    private static function links(array $creative): array
    {
        $links = [];

        array_walk_recursive($creative, function ($value, $key) use (&$links) {
            if (in_array($key, ['link', 'url', 'website_url'], true) && is_string($value) && str_starts_with($value, 'http')) {
                $links[] = $value;
            }
        });

        return $links;
    }
}
