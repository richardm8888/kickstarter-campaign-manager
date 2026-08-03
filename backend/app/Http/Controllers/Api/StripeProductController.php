<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\Providers\StripeIntegration;
use App\Jobs\ImportStripeVipsJob;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Chooses which Stripe products count as a VIP upgrade. Anything not
 * listed is ordinary revenue and creates no subscriber.
 */
class StripeProductController extends Controller
{
    use AuthorizesRequests;

    public function index(Project $project, IntegrationManager $integrations): JsonResponse
    {
        $this->authorize('view', $project);

        $stripe = $integrations->for($project, 'stripe');
        $record = $project->integrations()->where('provider', 'stripe')->first();

        if (! $stripe instanceof StripeIntegration || $record === null || ! $record->isConnected()) {
            return response()->json(['connected' => false, 'products' => [], 'vip_product_ids' => []]);
        }

        try {
            $products = $stripe->products();
        } catch (Throwable $e) {
            return response()->json([
                'connected' => true,
                'products' => [],
                'vip_product_ids' => $record->settings['vip_product_ids'] ?? [],
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'connected' => true,
            'products' => $products,
            'vip_product_ids' => $record->settings['vip_product_ids'] ?? [],
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            'vip_product_ids' => ['present', 'array'],
            'vip_product_ids.*' => ['string', 'max:128'],
        ]);

        $record = $project->integrations()->where('provider', 'stripe')->firstOrFail();

        $record->update([
            'settings' => [...$record->settings ?? [], 'vip_product_ids' => array_values($validated['vip_product_ids'])],
        ]);

        // Pick up purchases already made under the newly selected products.
        ImportStripeVipsJob::dispatch($project);

        return response()->json([
            'vip_product_ids' => $record->settings['vip_product_ids'],
            'message' => $validated['vip_product_ids'] === []
                ? 'No products selected — Stripe purchases will not create VIPs.'
                : 'Saved. Checking recent purchases now.',
        ]);
    }
}
