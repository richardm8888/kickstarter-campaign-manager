<?php

namespace App\AI;

use App\Models\Project;
use App\Recommendations\CampaignHealthScorer;

/**
 * One-stop audit combining health, readiness and open recommendations —
 * powers the Campaign Health screen.
 */
class CampaignAuditor
{
    public function __construct(
        private readonly CampaignHealthScorer $health,
        private readonly LaunchReadinessChecker $readiness,
        private readonly RecommendationGenerator $recommendations,
    ) {}

    public function audit(Project $project): array
    {
        $this->recommendations->generate($project);

        return [
            ...$this->health->score($project),
            'readiness' => $this->readiness->check($project),
            'recommendations' => $project->insights()
                ->where('kind', 'recommendation')
                ->whereNull('acknowledged_at')
                ->latest()
                ->take(10)
                ->get(),
        ];
    }
}
