<?php

namespace App\Services\PageAudit;

/**
 * One deterministic verdict about a page.
 *
 * A check has three outcomes, not two. Some things cannot be read from a
 * page we did not build — Kickstarter changes its markup without notice,
 * and a JavaScript-rendered section may never appear in the HTML we fetch.
 * Scoring those as failures would punish a creator for our blind spots, so
 * an undetermined check is excluded from the score entirely and says so.
 */
final readonly class PageCheck
{
    public const PASS = 'pass';

    public const FAIL = 'fail';

    public const UNKNOWN = 'unknown';

    private function __construct(
        public string $key,
        public string $label,
        public string $result,
        public int $weight,
        public string $recommendation,
        /** What was measured, when a number makes the verdict concrete. */
        public ?string $detail = null,
    ) {}

    public static function pass(string $key, string $label, int $weight, ?string $detail = null): self
    {
        return new self($key, $label, self::PASS, $weight, '', $detail);
    }

    public static function fail(string $key, string $label, int $weight, string $recommendation, ?string $detail = null): self
    {
        return new self($key, $label, self::FAIL, $weight, $recommendation, $detail);
    }

    /** Neither passed nor failed: we could not tell, and we say so. */
    public static function unknown(string $key, string $label, string $reason): self
    {
        return new self($key, $label, self::UNKNOWN, 0, $reason);
    }

    /** Convenience for the common "this is either true or it isn't" case. */
    public static function when(
        bool $passed,
        string $key,
        string $label,
        int $weight,
        string $recommendation,
        ?string $detail = null,
    ): self {
        return $passed
            ? self::pass($key, $label, $weight, $detail)
            : self::fail($key, $label, $weight, $recommendation, $detail);
    }

    public function passed(): bool
    {
        return $this->result === self::PASS;
    }

    public function scored(): bool
    {
        return $this->result !== self::UNKNOWN;
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'result' => $this->result,
            // Kept for clients written against the original two-state shape.
            'passed' => $this->passed(),
            'weight' => $this->weight,
            'recommendation' => $this->recommendation,
            'detail' => $this->detail,
        ];
    }
}
