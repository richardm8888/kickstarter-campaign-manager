<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;

class MailerLiteIntegration extends BaseIntegration
{
    public function provider(): string
    {
        return 'mailerlite';
    }

    public function displayName(): string
    {
        return 'MailerLite';
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API key',
                'help' => 'MailerLite → Integrations → API → Generate new token.',
                'type' => 'password',
            ],
        ];
    }

    public function docsUrl(): ?string
    {
        return 'https://developers.mailerlite.com/docs/#authentication';
    }

    /**
     * Adds (or updates) a subscriber. MailerLite upserts on email, so this
     * is safe to retry.
     */
    public function addSubscriber(string $email, array $fields = []): bool
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return false;
        }

        return Http::withToken($record->credentials['api_key'])
            ->acceptJson()
            ->post('https://connect.mailerlite.com/api/subscribers', array_filter([
                'email' => $email,
                'fields' => $fields ?: null,
            ]))
            ->successful();
    }

    protected function fetchMetrics(array $credentials): array
    {
        $stats = Http::withToken($credentials['api_key'])
            ->acceptJson()
            ->get('https://connect.mailerlite.com/api/subscribers', ['limit' => 0])
            ->throw()
            ->json('total', 0);

        return [
            [
                'metric' => 'email_subscribers',
                'value' => (float) $stats,
                'recorded_at' => now(),
            ],
        ];
    }
}
