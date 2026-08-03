<?php

namespace Database\Factories;

use App\Models\Insight;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Insight>
 */
class InsightFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'kind' => Insight::KIND_INSIGHT,
            'severity' => 'info',
            'title' => $this->faker->sentence(4),
            'body' => $this->faker->sentence(10),
            'action' => null,
            'metadata' => null,
        ];
    }
}
