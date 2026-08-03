<?php

namespace App\Integrations;

use App\Integrations\Contracts\Integration as IntegrationContract;
use App\Models\Integration as IntegrationModel;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Validation\ValidationException;
use Throwable;

abstract class BaseIntegration implements IntegrationContract
{
    public function __construct(
        protected readonly Project $project,
        protected readonly MetricRecorder $metrics,
    ) {}

    /**
     * Fetch metrics from the provider API. Returns a list of
     * [metric, value, recorded_at, dimensions?] tuples to record.
     */
    abstract protected function fetchMetrics(array $credentials): array;

    /** Derived from the field definitions so the two can never drift apart. */
    public function requiredCredentials(): array
    {
        return array_keys($this->credentialFields());
    }

    public function docsUrl(): ?string
    {
        return null;
    }

    /** Provider-specific credential checks beyond "is present". */
    protected function validateCredentials(array $credentials): void
    {
        //
    }

    public function connect(array $credentials): void
    {
        $missing = array_diff($this->requiredCredentials(), array_keys(array_filter($credentials)));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'credentials' => ['Missing credentials: '.implode(', ', $missing)],
            ]);
        }

        $this->validateCredentials($credentials);

        $this->record()->fill([
            'credentials' => array_intersect_key($credentials, array_flip($this->requiredCredentials())),
            'status' => IntegrationModel::STATUS_CONNECTED,
            'status_message' => null,
        ])->save();
    }

    public function disconnect(): void
    {
        $this->record()->fill([
            'credentials' => null,
            'status' => IntegrationModel::STATUS_DISCONNECTED,
            'status_message' => null,
        ])->save();
    }

    public function sync(): SyncResult
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return SyncResult::failure('Integration is not connected.');
        }

        try {
            $rows = $this->fetchMetrics($record->credentials);
        } catch (Throwable $e) {
            $record->fill([
                'status' => IntegrationModel::STATUS_ERROR,
                'status_message' => $e->getMessage(),
            ])->save();

            return SyncResult::failure($e->getMessage());
        }

        foreach ($rows as $row) {
            $this->metrics->record(
                $this->project,
                $this->provider(),
                $row['metric'],
                (float) $row['value'],
                $row['recorded_at'],
                $row['dimensions'] ?? null,
            );
        }

        $record->fill([
            'status' => IntegrationModel::STATUS_CONNECTED,
            'status_message' => null,
            'last_synced_at' => now(),
        ])->save();

        return SyncResult::success(count($rows));
    }

    public function status(): array
    {
        $record = IntegrationModel::query()
            ->where('project_id', $this->project->id)
            ->where('provider', $this->provider())
            ->first();

        return [
            'provider' => $this->provider(),
            'display_name' => $this->displayName(),
            'required_credentials' => $this->requiredCredentials(),
            'credential_fields' => $this->credentialFields(),
            'docs_url' => $this->docsUrl(),
            'status' => $record?->status ?? IntegrationModel::STATUS_DISCONNECTED,
            'status_message' => $record?->status_message,
            'last_synced_at' => $record?->last_synced_at?->toIso8601String(),
        ];
    }

    protected function record(): IntegrationModel
    {
        return IntegrationModel::query()->firstOrNew([
            'project_id' => $this->project->id,
            'provider' => $this->provider(),
        ]);
    }
}
