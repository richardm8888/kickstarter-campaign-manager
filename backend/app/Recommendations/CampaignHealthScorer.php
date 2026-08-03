<?php

namespace App\Recommendations;

use App\Forecasting\ForecastEngine;
use App\Models\Integration;
use App\Models\Project;
use App\Services\Analytics\MetricSeries;

/**
 * Scores overall launch readiness 0–100 from weighted factors.
 * Every factor carries a concrete recommendation so the score
 * always leads to an action, not just a number.
 */
class CampaignHealthScorer
{
    public function __construct(
        private readonly MetricSeries $series,
        private readonly ForecastEngine $forecasts,
    ) {}

    public function score(Project $project): array
    {
        $factors = [
            $this->landingPage($project),
            $this->tracking($project),
            $this->emails($project),
            $this->ads($project),
            $this->fundingPrediction($project),
            $this->traffic($project),
            $this->video($project),
            $this->assets($project),
        ];

        $totalWeight = array_sum(array_map(fn (HealthFactor $f) => $f->weight, $factors));
        $weighted = array_sum(array_map(fn (HealthFactor $f) => $f->score * $f->weight, $factors));

        return [
            'score' => (int) round($weighted / max(1, $totalWeight)),
            'factors' => array_map(fn (HealthFactor $f) => $f->toArray(), $factors),
        ];
    }

    private function landingPage(Project $project): HealthFactor
    {
        // A creator using their own page is scored on its latest analysis.
        if ($project->usesExternalLandingPage()) {
            $analysis = $project->landingPageAnalyses()->latest()->first();

            return new HealthFactor('landing_page', 'Landing page', $analysis?->score ?? 0, 20, match (true) {
                $analysis === null => 'Analyse your landing page so we can score it and suggest fixes.',
                $analysis->score >= 80 => 'Your landing page covers the essentials. Keep testing the headline.',
                default => $analysis->summary ?? 'Work through the failed checks on your landing page.',
            });
        }

        $page = $project->landingPage()->with('sections')->first();

        $score = match (true) {
            $page === null => 0,
            ! $page->published => 40,
            $page->sections->where('enabled', true)->count() < 5 => 70,
            default => 100,
        };

        return new HealthFactor('landing_page', 'Landing page', $score, 20, match (true) {
            $page === null => 'Create your landing page — it is where every visitor converts.',
            ! $page->published => 'Publish your landing page so ads have somewhere to send traffic.',
            $score < 100 => 'Enable more sections: social proof and FAQs lift conversion.',
            default => 'Landing page looks complete. Keep testing your hero copy.',
        });
    }

    private function tracking(Project $project): HealthFactor
    {
        $connected = $project->integrations()
            ->where('status', Integration::STATUS_CONNECTED)
            ->pluck('provider');

        $score = (int) round(
            (($connected->contains('ga4') ? 1 : 0) + ($connected->contains('meta') ? 1 : 0)) / 2 * 100
        );

        return new HealthFactor('tracking', 'Tracking', $score, 15, match (true) {
            $score === 0 => 'Connect GA4 and Meta so every pound of ad spend is measurable.',
            $score < 100 => 'Connect the missing analytics integration to complete your funnel view.',
            default => 'Tracking is fully wired up.',
        });
    }

    private function emails(Project $project): HealthFactor
    {
        $subscribers = $project->subscribers()->count();
        $needed = $this->forecasts->subscribersNeeded($project);

        $score = match (true) {
            $subscribers === 0 => 0,
            $needed > $subscribers => 50,
            $needed > 0 => 75,
            default => 100,
        };

        return new HealthFactor('emails', 'Email list', $score, 20, match (true) {
            $subscribers === 0 => 'Start collecting emails now — your list is the #1 predictor of day-one funding.',
            $needed > 0 => "You need roughly {$needed} more subscribers to be on track for your goal.",
            default => 'Your list is large enough to hit your goal. Keep it warm.',
        });
    }

    private function ads(Project $project): HealthFactor
    {
        $spend = $this->series->sum($project, 'spend', 30, 'meta');
        $cpc = $this->series->latest($project, 'cpc', 'meta');

        $score = match (true) {
            $spend <= 0 => 0,
            $cpc === null => 60,
            $cpc <= 1.0 => 100,
            $cpc <= 2.0 => 75,
            default => 40,
        };

        return new HealthFactor('ads', 'Ads', $score, 10, match (true) {
            $spend <= 0 => 'Run a small Meta test campaign to find your cost per subscriber.',
            $cpc !== null && $cpc > 2.0 => 'Your CPC is high — test new creatives and narrower audiences.',
            default => 'Ad performance is healthy. Scale spend gradually.',
        });
    }

    private function fundingPrediction(Project $project): HealthFactor
    {
        $coverage = $this->forecasts->forProject($project, 0)->goalCoverage;

        $score = (int) round(min(1.0, $coverage) * 100);

        return new HealthFactor('funding_prediction', 'Funding forecast', $score, 15, match (true) {
            $coverage >= 1.0 => 'Forecast covers your goal — you are in a strong position to launch.',
            $coverage >= 0.5 => 'Forecast covers part of your goal. Grow the list or raise ad spend.',
            default => 'Forecast is well short of your goal. Focus everything on list growth.',
        });
    }

    private function traffic(Project $project): HealthFactor
    {
        $sessions = $this->series->sum($project, 'sessions', 7);

        $score = match (true) {
            $sessions <= 0 => 0,
            $sessions < 100 => 40,
            $sessions < 1000 => 75,
            default => 100,
        };

        return new HealthFactor('traffic', 'Traffic', $score, 10, match (true) {
            $sessions <= 0 => 'No traffic recorded this week. Drive visitors with ads or social posts.',
            $sessions < 100 => 'Traffic is light. Aim for a steady daily flow of visitors.',
            default => 'Traffic volume is solid.',
        });
    }

    private function video(Project $project): HealthFactor
    {
        $section = $project->landingPage?->sections()
            ->where('type', 'video')
            ->where('enabled', true)
            ->first();

        $hasVideo = filled($section?->content['url'] ?? null);

        return new HealthFactor(
            'video',
            'Video',
            $hasVideo ? 100 : 0,
            5,
            $hasVideo
                ? 'Video is in place — campaigns with video raise significantly more.'
                : 'Add a product video: campaigns with video fund far more often.',
        );
    }

    private function assets(Project $project): HealthFactor
    {
        $hero = $project->landingPage?->sections()
            ->where('type', 'hero')
            ->where('enabled', true)
            ->first();

        $complete = filled($hero?->content['headline'] ?? null)
            && filled($hero?->content['image'] ?? null);

        return new HealthFactor(
            'assets',
            'Assets',
            $complete ? 100 : ($hero ? 50 : 0),
            5,
            $complete
                ? 'Core creative assets are ready.'
                : 'Finish your hero headline and imagery — first impressions decide conversion.',
        );
    }
}
