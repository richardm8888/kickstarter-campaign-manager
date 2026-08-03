<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\LandingPage>
 */
class LandingPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'template' => 'classic',
            'slug' => Str::lower(Str::random(10)),
            'published' => false,
            'theme' => ['accent' => '#05ce78', 'mode' => 'dark'],
        ];
    }
}
