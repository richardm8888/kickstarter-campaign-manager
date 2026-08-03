<?php

namespace App\Integrations\Providers;

use App\Integrations\BaseIntegration;
use Illuminate\Support\Facades\Http;

class StripeIntegration extends BaseIntegration
{
    public function provider(): string
    {
        return 'stripe';
    }

    public function displayName(): string
    {
        return 'Stripe';
    }

    public function credentialFields(): array
    {
        return [
            'secret_key' => [
                'label' => 'Secret key',
                'help' => 'Stripe → Developers → API keys. A restricted key with read access to charges is enough.',
                'type' => 'password',
                'placeholder' => 'sk_live_… or rk_live_…',
            ],
        ];
    }

    public function docsUrl(): ?string
    {
        return 'https://dashboard.stripe.com/apikeys';
    }

    protected function fetchMetrics(array $credentials): array
    {
        $charges = Http::withToken($credentials['secret_key'])
            ->get('https://api.stripe.com/v1/charges', [
                'limit' => 100,
                'created[gte]' => now()->subDay()->startOfDay()->timestamp,
                'created[lt]' => now()->startOfDay()->timestamp,
            ])->throw()->json('data', []);

        $succeeded = array_filter($charges, fn (array $c) => ($c['status'] ?? null) === 'succeeded');
        $revenue = array_sum(array_map(fn (array $c) => $c['amount'] / 100, $succeeded));
        $recordedAt = now()->subDay()->toDateString();

        return [
            ['metric' => 'revenue', 'value' => $revenue, 'recorded_at' => $recordedAt],
            ['metric' => 'payments', 'value' => count($succeeded), 'recorded_at' => $recordedAt],
        ];
    }
}
