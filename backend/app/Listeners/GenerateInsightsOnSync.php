<?php

namespace App\Listeners;

use App\AI\InsightGenerator;
use App\Events\IntegrationSynced;
use Illuminate\Contracts\Queue\ShouldQueue;

class GenerateInsightsOnSync implements ShouldQueue
{
    public function __construct(private readonly InsightGenerator $insights) {}

    public function handle(IntegrationSynced $event): void
    {
        $this->insights->generate($event->project);
    }
}
