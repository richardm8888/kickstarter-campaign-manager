<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MailerLiteIntegration extends BaseIntegration
{
    /**
     * How many recent campaigns to fetch link detail for each sync.
     * One request each, so this is the bound on the extra traffic.
     */
    private const LINK_DETAIL_CAMPAIGNS = 10;

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
    public function addSubscriber(string $email, array $fields = [], array $groupIds = []): bool
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return false;
        }

        // Callers may target a specific group (VIPs); otherwise the
        // project's default group applies.
        $groups = $groupIds !== []
            ? $groupIds
            : array_filter([$record->settings['group_id'] ?? null]);

        return Http::withToken($record->credentials['api_key'])
            ->acceptJson()
            ->post('https://connect.mailerlite.com/api/subscribers', array_filter([
                'email' => $email,
                'fields' => $fields ?: null,
                'groups' => $groups !== [] ? array_map('strval', array_values($groups)) : null,
            ]))
            ->successful();
    }

    /** The group VIP purchasers should join, falling back to the default. */
    public function vipGroupIds(): array
    {
        $settings = $this->record()->settings ?? [];

        return array_values(array_filter([
            $settings['vip_group_id'] ?? $settings['group_id'] ?? null,
        ]));
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

        // Unfiltered, this counts everyone who was ever added — including
        // people who have unsubscribed or bounced. That figure only ever
        // rises, so a shrinking list reads as a flat one and every
        // forecast built on it drifts further out as the campaign ages.
        $total = Http::withToken($credentials['api_key'])
            ->acceptJson()
            ->get('https://connect.mailerlite.com/api/subscribers', [
                'limit' => 0,
                'filter[status]' => 'active',
            ])
            ->throw()
            ->json('total', 0);

        $rows[] = ['metric' => 'email_subscribers', 'value' => (float) $total, 'recorded_at' => now()];

        $this->reconcileUnsubscribes($credentials);

        return [
            ...$rows,
            ...$this->groupMetrics(),
            ...$this->campaignMetrics($credentials),
            ...$this->cohortMetrics($credentials),
        ];
    }

    /**
     * Marks people who left in MailerLite as gone here too.
     *
     * Unsubscribing happens in the email, so MailerLite learns about it
     * and we never would. Our own rows would otherwise keep counting
     * someone who has left — and those rows are what the funnel, the
     * conversion rates and the growth curve are built from.
     *
     * Runs on the sync rather than as its own job because it needs the
     * same credentials and the same hourly cadence; a departure being
     * recognised an hour late costs nothing.
     */
    private function reconcileUnsubscribes(array $credentials): void
    {
        $emails = [];
        $cursor = null;

        // Bounded like the cohort pass, so a large list cannot stall the
        // sync. Departures are rare enough that ten pages is generous.
        for ($page = 0; $page < 10; $page++) {
            $response = Http::withToken($credentials['api_key'])
                ->acceptJson()
                ->get('https://connect.mailerlite.com/api/subscribers', array_filter([
                    'limit' => 100,
                    'cursor' => $cursor,
                    'filter[status]' => 'unsubscribed',
                ]))
                ->throw()
                ->json();

            $people = $response['data'] ?? [];

            foreach ($people as $person) {
                if (filled($person['email'] ?? null)) {
                    $emails[] = mb_strtolower($person['email']);
                }
            }

            $cursor = $response['meta']['next_cursor'] ?? null;

            if ($cursor === null || $people === []) {
                break;
            }
        }

        if ($emails === []) {
            return;
        }

        // Only rows not already marked, so the date stays the first time
        // we saw them leave rather than moving forward every hour.
        $this->project->subscribers()
            ->whereNull('unsubscribed_at')
            ->whereIn('email', $emails)
            ->update(['unsubscribed_at' => now()]);
    }

    /**
     * Sizes of the configured groups, so a VIP segment maintained in
     * MailerLite is reflected here without anyone re-counting by hand.
     */
    private function groupMetrics(): array
    {
        $settings = $this->record()->settings ?? [];

        $wanted = array_filter([
            'email_subscribers_in_group' => $settings['group_id'] ?? null,
            'email_vip_subscribers' => $settings['vip_group_id'] ?? null,
        ]);

        if ($wanted === []) {
            return [];
        }

        $groups = collect($this->groups())->keyBy('id');

        $rows = [];

        foreach ($wanted as $metric => $groupId) {
            if ($group = $groups->get((string) $groupId)) {
                $rows[] = [
                    'metric' => $metric,
                    'value' => (float) $group['total'],
                    'recorded_at' => now(),
                ];
            }
        }

        return $rows;
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
        $rows = [];

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

            // The send itself, kept as its own row.
            //
            // The aggregates above answer "how did email do that day".
            // They cannot answer "what did this email do", because the
            // campaign's name and identity are gone by the time they are
            // written — and follower lift is measured per send, against
            // the days either side of it.
            $rows[] = [
                'metric' => 'email_campaign_sent',
                'value' => (float) ($stats['sent'] ?? $stats['sent_count'] ?? 0),
                'recorded_at' => $date,
                'dimensions' => [
                    'campaign_id' => (string) ($campaign['id'] ?? $date),
                    'campaign_name' => $campaign['name'] ?? 'Untitled campaign',
                    'subject' => $campaign['emails'][0]['subject'] ?? null,
                ],
            ];
        }

        foreach ($byDate as $date => $metrics) {
            foreach ($metrics as $metric => $value) {
                $rows[] = ['metric' => $metric, 'value' => $value, 'recorded_at' => $date];
            }
        }

        return [...$rows, ...$this->linkClicks($credentials, $campaigns)];
    }

    /**
     * Clicks on each link inside a campaign.
     *
     * The campaign list carries a total; which link earned it lives on the
     * campaign itself, so this fetches the recent ones individually.
     *
     * **The shape of that per-link data is not verified against a live
     * account.** MailerLite's docs were unreachable from where this was
     * written and there was no key to probe with, so `linksFrom` reads a
     * few plausible spellings rather than one asserted from memory —
     * which is how a previous guess in this project ended up measuring an
     * event nobody had installed. When none of them match, it says so in
     * the log with the keys that were actually there, so the real shape
     * can be read off one sync rather than guessed at again.
     *
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    private function linkClicks(array $credentials, array $campaigns): array
    {
        $recent = array_slice(array_filter(
            $campaigns,
            fn (array $campaign) => filled($campaign['finished_at'] ?? $campaign['scheduled_for'] ?? null),
        ), 0, self::LINK_DETAIL_CAMPAIGNS);

        $rows = [];
        $anyFound = false;

        foreach ($recent as $campaign) {
            $id = (string) ($campaign['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $detail = Http::withToken($credentials['api_key'])
                ->acceptJson()
                ->get("https://connect.mailerlite.com/api/campaigns/{$id}")
                ->json('data', []);

            $links = $this->linksFrom(is_array($detail) ? $detail : []);

            if ($links === []) {
                continue;
            }

            $anyFound = true;
            $date = substr((string) ($campaign['finished_at'] ?? $campaign['scheduled_for']), 0, 10);

            foreach ($links as $url => $clicks) {
                $rows[] = [
                    'metric' => 'email_link_clicks',
                    'value' => (float) $clicks,
                    'recorded_at' => $date,
                    'dimensions' => [
                        'url' => $url,
                        'campaign_id' => $id,
                        'campaign_name' => $campaign['name'] ?? 'Untitled campaign',
                    ],
                ];
            }
        }

        if (! $anyFound && $recent !== []) {
            Log::info('MailerLite returned no per-link click data in a recognised shape', [
                'project_id' => $this->project->id,
                // The keys that were there, so the shape can be pinned
                // from one log line instead of another round of guessing.
                'campaign_keys' => array_keys($recent[0]),
                'stats_keys' => array_keys($recent[0]['stats'] ?? []),
            ]);
        }

        return $rows;
    }

    /**
     * Per-link clicks out of a campaign payload, whatever it calls them.
     *
     * Candidate spellings, not a documented contract — see linkClicks.
     *
     * @param  array<string, mixed>  $campaign
     * @return array<string, float> clicks keyed by URL
     */
    private function linksFrom(array $campaign): array
    {
        $candidates = [
            $campaign['stats']['clicks_by_link'] ?? null,
            $campaign['clicks_by_link'] ?? null,
            $campaign['stats']['links'] ?? null,
            $campaign['links'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (! is_array($candidate) || $candidate === []) {
                continue;
            }

            $links = [];

            foreach ($candidate as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $url = $entry['url'] ?? $entry['link'] ?? null;
                $clicks = $entry['count'] ?? $entry['clicks'] ?? $entry['clicks_count'] ?? $entry['total'] ?? null;

                if (filled($url) && $clicks !== null) {
                    // Summed, because the same URL can appear more than
                    // once when an email links to it from several places.
                    $links[(string) $url] = ($links[(string) $url] ?? 0) + (float) $clicks;
                }
            }

            if ($links !== []) {
                return $links;
            }
        }

        return [];
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
