<?php

namespace Database\Factories;

use App\Models\DailyTask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DailyTask> */
class DailyTaskFactory extends Factory
{
    protected $model = DailyTask::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'for_date' => now()->toDateString(),
            'signal_key' => 'bottleneck_email_to_follower',
            'priority' => DailyTask::HIGH,
            'title' => 'Ask your email list to follow the Kickstarter page',
            'why' => '100 subscribers have produced 20 followers.',
            'action' => 'Send one short email.',
            'effort_minutes' => 20,
            'impact' => DailyTask::HIGH,
            'evidence' => ['subscribers' => 100, 'followers' => 20],
            'score' => 1.5,
            'status' => DailyTask::OPEN,
        ];
    }
}
