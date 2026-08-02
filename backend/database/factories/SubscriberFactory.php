<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Subscriber>
 */
class SubscriberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'email' => $this->faker->unique()->safeEmail(),
            'is_vip' => false,
            'source' => 'landing_page',
        ];
    }

    public function vip(): static
    {
        return $this->state(['is_vip' => true]);
    }
}
