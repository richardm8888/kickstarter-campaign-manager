<?php

namespace App\Actions;

use App\Integrations\IntegrationManager;
use App\Integrations\Providers\MailerLiteIntegration;
use App\Integrations\Providers\StripeIntegration;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns Stripe purchases of the configured VIP products into VIP
 * subscribers, and puts them in the email provider's VIP group.
 *
 * Replaces the usual chain of a store plus a third-party automation tool:
 * the purchase, the person and the list membership are handled in one pass,
 * and the dashboard's VIP count reflects it.
 */
class ImportStripeVips
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly MetricRecorder $metrics,
    ) {}

    /** @return array{found: int, imported: int, forwarded: int, already_vip: int} */
    public function handle(Project $project, int $days = 30, bool $resync = false): array
    {
        $stripe = $this->integrations->for($project, 'stripe');

        if (! $stripe instanceof StripeIntegration) {
            return ['found' => 0, 'imported' => 0, 'forwarded' => 0, 'already_vip' => 0];
        }

        try {
            $purchases = $stripe->fetchVipPurchases($days);
        } catch (Throwable $e) {
            Log::warning('Could not fetch Stripe purchases', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $mailer = $this->integrations->for($project, 'mailerlite');
        $canForward = $mailer instanceof MailerLiteIntegration
            && $mailer->status()['status'] === 'connected';

        $imported = 0;
        $forwarded = 0;
        $alreadyVip = 0;

        foreach ($purchases as $purchase) {
            $subscriber = $project->subscribers()->firstOrNew(['email' => $purchase['email']]);

            $wasVip = $subscriber->exists && $subscriber->is_vip;

            if ($wasVip) {
                $alreadyVip++;
            }

            $subscriber->fill([
                'is_vip' => true,
                'source' => $subscriber->exists ? $subscriber->source : 'stripe',
                'external_id' => $subscriber->external_id ?? $purchase['id'],
                'fields' => [
                    ...$subscriber->fields ?? [],
                    'vip_product_id' => $purchase['product_id'],
                    'vip_purchased_at' => $purchase['created_at'],
                ],
            ]);

            if ($subscriber->isDirty() || ! $subscriber->exists) {
                $subscriber->save();
            }

            if (! $wasVip) {
                $imported++;
                $this->metrics->record(
                    $project, 'stripe', 'vip_upgrades', 1, $purchase['created_at'],
                );
            }

            // Put them in the VIP group; already-synced contacts are only
            // re-sent when explicitly asked, to avoid pointless API calls.
            if ($canForward && (! $wasVip || $resync)) {
                if ($mailer->addSubscriber($purchase['email'], $subscriber->fields ?? [], $mailer->vipGroupIds())) {
                    $subscriber->forceFill(['synced_to_email_at' => now()])->save();
                    $forwarded++;
                }
            }
        }

        $summary = [
            'found' => count($purchases),
            'imported' => $imported,
            'forwarded' => $forwarded,
            'already_vip' => $alreadyVip,
        ];

        Log::info('Imported Stripe VIP purchases', ['project_id' => $project->id] + $summary);

        return $summary;
    }
}
