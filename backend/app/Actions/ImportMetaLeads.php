<?php

namespace App\Actions;

use App\Integrations\IntegrationManager;
use App\Integrations\Providers\MailerLiteIntegration;
use App\Integrations\Providers\MetaIntegration;
use App\Models\Project;
use App\Services\Analytics\MetricRecorder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls Instant Form leads out of Meta and into the project's list.
 *
 * Leads submitted through a Meta form stay inside Facebook. Left there
 * they are unreachable at launch, which is the moment they matter most —
 * so they are imported as subscribers and forwarded to the email provider.
 */
class ImportMetaLeads
{
    public function __construct(
        private readonly IntegrationManager $integrations,
        private readonly MetricRecorder $metrics,
    ) {}

    /** @return array{imported: int, forwarded: int, skipped: int} */
    public function handle(Project $project, int $days = 30): array
    {
        $meta = $this->integrations->for($project, 'meta');

        if (! $meta instanceof MetaIntegration) {
            return ['imported' => 0, 'forwarded' => 0, 'skipped' => 0];
        }

        try {
            $leads = $meta->fetchFormLeads($days);
        } catch (Throwable $e) {
            Log::warning('Could not fetch Meta form leads', [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $mailer = $this->integrations->for($project, 'mailerlite');
        $canForward = $mailer instanceof MailerLiteIntegration && $mailer->status()['status'] === 'connected';

        $imported = 0;
        $forwarded = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            if (blank($lead['email']) || blank($lead['id'])) {
                $skipped++;

                continue;
            }

            $email = strtolower(trim($lead['email']));
            $fields = $this->fieldsFor($lead);

            $subscriber = $project->subscribers()->firstOrNew(['email' => $email]);

            $isNew = ! $subscriber->exists;

            $subscriber->fill([
                'external_id' => $lead['id'],
                'source' => 'meta_lead_form',
                // Keep anything already known, let the newer answers win.
                'fields' => [...$subscriber->fields ?? [], ...$fields],
            ]);

            if ($isNew) {
                $subscriber->save();
                $imported++;
                $this->metrics->record($project, 'meta', 'signups', 1);
            } elseif ($subscriber->isDirty()) {
                $subscriber->save();
            }

            // Forward anyone the email provider has not seen yet.
            if ($canForward && $subscriber->synced_to_email_at === null) {
                if ($mailer->addSubscriber($email, $subscriber->fields ?? [])) {
                    $subscriber->forceFill(['synced_to_email_at' => now()])->save();
                    $forwarded++;
                }
            }
        }

        // Fall through to the log summary below.
        Log::info('Imported Meta form leads', [
            'project_id' => $project->id,
            'imported' => $imported,
            'forwarded' => $forwarded,
            'skipped' => $skipped,
        ]);

        return ['imported' => $imported, 'forwarded' => $forwarded, 'skipped' => $skipped];
    }

    /**
     * Everything worth carrying to the email provider: the form's own
     * answers, plus lead_id and lead_source so a contact can always be
     * traced back to the ad that produced it. A question the creator asked
     * always wins over our derived value.
     */
    private function fieldsFor(array $lead): array
    {
        $derived = array_filter([
            'lead_id' => $lead['id'] ?? null,
            'lead_source' => $lead['campaign_name'] ?? $lead['ad_name'] ?? 'Meta Instant Form',
            'name' => $lead['name'] ?? null,
        ]);

        // Form answers override the defaults, never the other way round.
        return [...$derived, ...($lead['fields'] ?? [])];
    }
}
