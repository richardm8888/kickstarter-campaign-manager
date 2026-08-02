<?php

namespace App\Recommendations;

final readonly class HealthFactor
{
    public function __construct(
        public string $key,
        public string $label,
        public int $score,
        public int $weight,
        public string $recommendation,
    ) {}

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'score' => $this->score,
            'weight' => $this->weight,
            'recommendation' => $this->recommendation,
        ];
    }
}
