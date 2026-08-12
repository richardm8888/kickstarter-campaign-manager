<?php

namespace App\Services\PageAudit;

final readonly class FetchedPage
{
    public function __construct(
        public string $html,
        public string $url,
        public int $status,
        public int $elapsedMs,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
