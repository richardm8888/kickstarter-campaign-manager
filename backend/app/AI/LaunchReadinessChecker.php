<?php

namespace App\AI;

use App\Models\Project;
use App\Recommendations\CampaignHealthScorer;

/**
 * Boils campaign health down to a launch / don't-launch answer
 * with the specific blockers that must be cleared first.
 */
class LaunchReadinessChecker
{
    private const READY_THRESHOLD = 70;

    public function __construct(private readonly CampaignHealthScorer $health) {}

    public function check(Project $project): array
    {
        $health = $this->health->score($project);

        $blockers = array_values(array_filter(
            $health['factors'],
            fn (array $f) => $f['score'] < 50 && $f['weight'] >= 10,
        ));

        return [
            'ready' => $health['score'] >= self::READY_THRESHOLD && $blockers === [],
            'score' => $health['score'],
            'blockers' => $blockers,
        ];
    }
}
