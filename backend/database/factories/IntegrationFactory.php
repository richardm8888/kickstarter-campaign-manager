<?php

namespace Database\Factories;

use App\Models\Integration;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Integration>
 */
class IntegrationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'provider' => $this->faker->randomElement(['meta', 'ga4', 'mailerlite', 'stripe']),
            'credentials' => ['api_key' => 'test-key'],
            'status' => Integration::STATUS_CONNECTED,
        ];
    }

    public function connected(): static
    {
        return $this->state(['status' => Integration::STATUS_CONNECTED]);
    }

    public function disconnected(): static
    {
        return $this->state([
            'status' => Integration::STATUS_DISCONNECTED,
            'credentials' => null,
        ]);
    }
}
