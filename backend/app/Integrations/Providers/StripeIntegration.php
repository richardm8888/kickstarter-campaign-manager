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

    /**
     * Products on the account, for choosing which purchases count as a VIP
     * upgrade. Only active ones, newest first.
     *
     * @return list<array{id: string, name: string}>
     */
    public function products(): array
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return [];
        }

        $products = Http::withToken($record->credentials['secret_key'])
            ->get('https://api.stripe.com/v1/products', ['active' => 'true', 'limit' => 100])
            ->throw()
            ->json('data', []);

        return array_map(fn (array $product) => [
            'id' => (string) $product['id'],
            'name' => $product['name'] ?? $product['id'],
        ], $products);
    }

    /**
     * Completed checkouts that included one of the configured VIP products.
     *
     * Checkout sessions are used rather than charges because they carry
     * both the buyer's email and the line items, which is what lets a
     * purchase be matched to a product and to a person.
     *
     * @return list<array{id: string, email: string, amount: int, product_id: string, created_at: string}>
     */
    public function fetchVipPurchases(int $days = 30): array
    {
        $record = $this->record();

        if (! $record->isConnected() || $record->credentials === null) {
            return [];
        }

        $productIds = $record->settings['vip_product_ids'] ?? [];

        if (! is_array($productIds) || $productIds === []) {
            return [];
        }

        $sessions = Http::withToken($record->credentials['secret_key'])
            ->get('https://api.stripe.com/v1/checkout/sessions', [
                'limit' => 100,
                'created' => ['gte' => now()->subDays($days)->timestamp],
                'expand' => ['data.line_items'],
            ])
            ->throw()
            ->json('data', []);

        $purchases = [];

        foreach ($sessions as $session) {
            if (($session['payment_status'] ?? null) !== 'paid') {
                continue;
            }

            $email = $session['customer_details']['email'] ?? $session['customer_email'] ?? null;

            if (blank($email)) {
                continue;
            }

            foreach ($session['line_items']['data'] ?? [] as $item) {
                $productId = $item['price']['product'] ?? null;

                if ($productId === null || ! in_array($productId, $productIds, true)) {
                    continue;
                }

                $purchases[] = [
                    'id' => (string) $session['id'],
                    'email' => strtolower(trim($email)),
                    'amount' => (int) ($session['amount_total'] ?? 0),
                    'product_id' => (string) $productId,
                    'created_at' => isset($session['created'])
                        ? date('Y-m-d', (int) $session['created'])
                        : now()->toDateString(),
                ];

                // One VIP per checkout, however many matching lines it has.
                break;
            }
        }

        return $purchases;
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
