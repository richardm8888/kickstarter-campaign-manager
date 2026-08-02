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

    public function requiredCredentials(): array
    {
        return ['secret_key'];
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
