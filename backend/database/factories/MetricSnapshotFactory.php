<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\MetricSnapshot>
 */
class MetricSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source' => 'ga4',
            'metric' => 'sessions',
            'value' => $this->faker->numberBetween(10, 500),
            'dimensions' => null,
            'recorded_at' => now(),
        ];
    }

    public function metric(string $source, string $metric, float $value, \DateTimeInterface $recordedAt): static
    {
        return $this->state([
            'source' => $source,
            'metric' => $metric,
            'value' => $value,
            'recorded_at' => $recordedAt,
        ]);
    }
}
