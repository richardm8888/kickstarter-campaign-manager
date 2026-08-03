<?php

namespace App\Integrations;

final readonly class SyncResult
{
    private function __construct(
        public bool $ok,
        public int $metricsRecorded,
        public ?string $error = null,
    ) {}

    public static function success(int $metricsRecorded): self
    {
        return new self(true, $metricsRecorded);
    }

    public static function failure(string $error): self
    {
        return new self(false, 0, $error);
    }
}
