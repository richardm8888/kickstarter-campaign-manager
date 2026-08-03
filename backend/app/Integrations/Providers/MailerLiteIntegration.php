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
     * is safe to retry. When a group is configured, the contact joins it —
     * which is what automations and segments are usually built around.
     */
    public function addSubscriber(string $email, array $fields = []): bool
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return false;
        }

        $groupId = $record->settings['group_id'] ?? null;

        return Http::withToken($record->credentials['api_key'])
            ->acceptJson()
            ->post('https://connect.mailerlite.com/api/subscribers', array_filter([
                'email' => $email,
                'fields' => $fields ?: null,
                'groups' => filled($groupId) ? [(string) $groupId] : null,
            ]))
            ->successful();
    }

    /**
     * Groups available on the account, for choosing where imported
     * contacts should land.
     *
     * @return list<array{id: string, name: string, total: int}>
     */
    public function groups(): array
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return [];
        }

        $groups = Http::withToken($record->credentials['api_key'])
            ->acceptJson()
            ->get('https://connect.mailerlite.com/api/groups', ['limit' => 100])
            ->throw()
            ->json('data', []);

        return array_map(fn (array $group) => [
            'id' => (string) $group['id'],
            'name' => $group['name'] ?? 'Unnamed group',
            'total' => (int) ($group['active_count'] ?? 0),
        ], $groups);
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
