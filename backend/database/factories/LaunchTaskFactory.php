<?php

namespace Database\Factories;

use App\Models\LaunchTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LaunchTask> */
class LaunchTaskFactory extends Factory
{
    protected $model = LaunchTask::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'phase' => 'audience',
            'category' => 'bgg',
            'title' => 'BGG designer diary #1: the core mechanic',
            'details' => 'Explain the core mechanic.',
            'offset_days' => -84,
            'content_type' => 'bgg_diary',
            'status' => LaunchTask::TODO,
        ];
    }
}
