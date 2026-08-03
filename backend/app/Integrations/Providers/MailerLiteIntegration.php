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
        $rows = [];

        $total = Http::withToken($credentials['api_key'])
            ->acceptJson()
            ->get('https://connect.mailerlite.com/api/subscribers', ['limit' => 0])
            ->throw()
            ->json('total', 0);

        $rows[] = ['metric' => 'email_subscribers', 'value' => (float) $total, 'recorded_at' => now()];

        return [...$rows, ...$this->campaignMetrics($credentials), ...$this->cohortMetrics($credentials)];
    }

    /**
     * Opens, clicks and unsubscribes from sent campaigns, recorded against
     * the day each campaign went out.
     */
    private function campaignMetrics(array $credentials): array
    {
        $campaigns = Http::withToken($credentials['api_key'])
            ->acceptJson()
            ->get('https://connect.mailerlite.com/api/campaigns', [
                'filter' => ['status' => 'sent'],
                'limit' => 50,
            ])
            ->throw()
            ->json('data', []);

        $byDate = [];

        foreach ($campaigns as $campaign) {
            $sentAt = $campaign['finished_at'] ?? $campaign['scheduled_for'] ?? null;

            if ($sentAt === null) {
                continue;
            }

            $date = substr((string) $sentAt, 0, 10);
            $stats = $campaign['stats'] ?? [];

            $byDate[$date] ??= ['email_opens' => 0.0, 'email_clicks' => 0.0, 'email_unsubscribes' => 0.0];
            $byDate[$date]['email_opens'] += (float) ($stats['opens_count'] ?? 0);
            $byDate[$date]['email_clicks'] += (float) ($stats['clicks_count'] ?? 0);
            $byDate[$date]['email_unsubscribes'] += (float) ($stats['unsubscribes_count'] ?? 0);
        }

        $rows = [];

        foreach ($byDate as $date => $metrics) {
            foreach ($metrics as $metric => $value) {
                $rows[] = ['metric' => $metric, 'value' => $value, 'recorded_at' => $date];
            }
        }

        return $rows;
    }

    /**
     * Splits the list by whether a contact carries a lead_id — i.e. came
     * from a Meta lead form — and compares how each cohort engages. A form
     * lead that never opens anything is worth far less than the cost per
     * lead suggests, and this is the only way to see that.
     */
    private function cohortMetrics(array $credentials): array
    {
        $cohorts = [
            'form' => ['opens' => 0.0, 'clicks' => 0.0, 'sent' => 0.0, 'people' => 0],
            'other' => ['opens' => 0.0, 'clicks' => 0.0, 'sent' => 0.0, 'people' => 0],
        ];

        $cursor = null;

        // Bounded so a large list cannot stall the hourly sync.
        for ($page = 0; $page < 10; $page++) {
            $response = Http::withToken($credentials['api_key'])
                ->acceptJson()
                ->get('https://connect.mailerlite.com/api/subscribers', array_filter([
                    'limit' => 100,
                    'cursor' => $cursor,
                ]))
                ->throw()
                ->json();

            $subscribers = $response['data'] ?? [];

            foreach ($subscribers as $subscriber) {
                $key = filled($subscriber['fields']['lead_id'] ?? null) ? 'form' : 'other';

                $cohorts[$key]['people']++;
                $cohorts[$key]['opens'] += (float) ($subscriber['opens_count'] ?? 0);
                $cohorts[$key]['clicks'] += (float) ($subscriber['clicks_count'] ?? 0);
                $cohorts[$key]['sent'] += (float) ($subscriber['sent'] ?? 0);
            }

            $cursor = $response['meta']['next_cursor'] ?? null;

            if ($cursor === null || $subscribers === []) {
                break;
            }
        }

        $rows = [];

        foreach ($cohorts as $key => $totals) {
            if ($totals['people'] === 0) {
                continue;
            }

            $rows[] = [
                'metric' => "email_{$key}_contacts",
                'value' => (float) $totals['people'],
                'recorded_at' => now(),
            ];

            // Rates need emails to have actually been sent to the cohort.
            if ($totals['sent'] > 0) {
                $rows[] = [
                    'metric' => "email_{$key}_open_rate",
                    'value' => round($totals['opens'] / $totals['sent'] * 100, 2),
                    'recorded_at' => now(),
                ];
                $rows[] = [
                    'metric' => "email_{$key}_click_rate",
                    'value' => round($totals['clicks'] / $totals['sent'] * 100, 2),
                    'recorded_at' => now(),
                ];
            }
        }

        return $rows;
    }
}
