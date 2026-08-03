<?php

namespace App\AI;

use App\Models\Insight;
use App\Models\Project;
use App\Recommendations\CampaignHealthScorer;

/**
 * Converts weak campaign-health factors into actionable recommendations.
 */
class RecommendationGenerator
{
    public function __construct(private readonly CampaignHealthScorer $health) {}

    /** @return list<Insight> newly created recommendations */
    public function generate(Project $project): array
    {
        $created = [];

        foreach ($this->health->score($project)['factors'] as $factor) {
            if ($factor['score'] >= 70 || $this->alreadyOpen($project, $factor['key'])) {
                continue;
            }

            $created[] = $project->insights()->create([
                'kind' => Insight::KIND_RECOMMENDATION,
                'severity' => $factor['score'] < 40 ? 'warning' : 'info',
                'title' => 'Improve your '.strtolower($factor['label']),
                'body' => $factor['recommendation'],
                'action' => $factor['recommendation'],
                'metadata' => ['factor' => $factor['key'], 'score' => $factor['score']],
            ]);
        }

        return $created;
    }

    private function alreadyOpen(Project $project, string $factorKey): bool
    {
        return $project->insights()
            ->where('kind', Insight::KIND_RECOMMENDATION)
            ->whereNull('acknowledged_at')
            ->where('metadata->factor', $factorKey)
            ->exists();
    }
}
