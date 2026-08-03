<?php

namespace App\Forecasting;

/**
 * The audience split into the three groups that back at different rates.
 *
 * Standard excludes VIPs: a paid reserver is already counted in the email
 * list, and counting them in both groups would forecast them backing twice.
 */
final readonly class AudienceMix
{
    public function __construct(
        public int $standard,
        public int $followers,
        public int $vips,
    ) {}

    public static function fromList(int $emailSubscribers, int $followers, int $vips): self
    {
        return new self(
            standard: max(0, $emailSubscribers - $vips),
            followers: max(0, $followers),
            vips: max(0, $vips),
        );
    }

    public function total(): int
    {
        return $this->standard + $this->followers + $this->vips;
    }

    /**
     * Each segment is floored separately so the total always equals the
     * sum of the per-segment figures shown next to it.
     *
     * @param  array<string, float>  $rates
     */
    public function backers(array $rates): int
    {
        return (int) floor($this->standard * ($rates[BackerRates::STANDARD] ?? 0))
            + (int) floor($this->followers * ($rates[BackerRates::FOLLOWERS] ?? 0))
            + (int) floor($this->vips * ($rates[BackerRates::VIPS] ?? 0));
    }

    /**
     * The single rate this particular mix behaves as. Useful for stating
     * what a plan needs, but never for reasoning about a segment.
     *
     * @param  array<string, float>  $rates
     */
    public function blendedRate(array $rates): float
    {
        $total = $this->total();

        return $total > 0 ? $this->backers($rates) / $total : 0.0;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            BackerRates::STANDARD => $this->standard,
            BackerRates::FOLLOWERS => $this->followers,
            BackerRates::VIPS => $this->vips,
        ];
    }

    public function withExtraStandard(int $extra, int $extraFollowers = 0, int $extraVips = 0): self
    {
        return new self(
            standard: $this->standard + max(0, $extra),
            followers: $this->followers + max(0, $extraFollowers),
            vips: $this->vips + max(0, $extraVips),
        );
    }
}
