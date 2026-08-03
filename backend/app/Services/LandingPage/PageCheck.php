<?php

namespace App\Services\LandingPage;

final readonly class PageCheck
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $passed,
        public int $weight,
        public string $recommendation,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'passed' => $this->passed,
            'weight' => $this->weight,
            'recommendation' => $this->recommendation,
        ];
    }
}
