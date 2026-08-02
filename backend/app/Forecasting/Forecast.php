<?php

namespace App\Forecasting;

final readonly class Forecast
{
    public function __construct(
        public int $projectedVisitors,
        public int $projectedSubscribers,
        public int $projectedVips,
        public int $expectedBackers,
        public int $expectedFunding,
        public int $fundingGoal,
        public float $goalCoverage,
        public string $confidence,
        public array $assumptions,
    ) {}

    public function toArray(): array
    {
        return [
            'projected_visitors' => $this->projectedVisitors,
            'projected_subscribers' => $this->projectedSubscribers,
            'projected_vips' => $this->projectedVips,
            'expected_backers' => $this->expectedBackers,
            'expected_funding' => $this->expectedFunding,
            'funding_goal' => $this->fundingGoal,
            'goal_coverage' => $this->goalCoverage,
            'confidence' => $this->confidence,
            'assumptions' => $this->assumptions,
        ];
    }
}
