<?php

namespace App\Integrations;

use App\Integrations\Contracts\Integration;
use App\Integrations\Providers\Ga4Integration;
use App\Integrations\Providers\MailerLiteIntegration;
use App\Integrations\Providers\MetaIntegration;
use App\Integrations\Providers\StripeIntegration;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use InvalidArgumentException;

class IntegrationManager
{
    /** @var array<string, class-string<BaseIntegration>> */
    protected array $providers = [
        'meta' => MetaIntegration::class,
        'ga4' => Ga4Integration::class,
        'mailerlite' => MailerLiteIntegration::class,
        'stripe' => StripeIntegration::class,
    ];

    public function __construct(protected readonly MetricRecorder $metrics) {}

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->providers);
    }

    public function for(Project $project, string $provider): Integration
    {
        $class = $this->providers[$provider]
            ?? throw new InvalidArgumentException("Unknown integration provider [{$provider}].");

        return new $class($project, $this->metrics);
    }

    /** @return list<Integration> */
    public function all(Project $project): array
    {
        return array_map(fn (string $p) => $this->for($project, $p), $this->providers());
    }
}
