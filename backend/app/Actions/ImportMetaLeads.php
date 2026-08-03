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

            $subscriber = $project->subscribers()->firstOrNew(['email' => $email]);

            $isNew = ! $subscriber->exists;

            $subscriber->fill([
                'external_id' => $lead['id'],
                'source' => 'meta_lead_form',
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
                if ($mailer->addSubscriber($email, array_filter(['name' => $lead['name']]))) {
                    $subscriber->forceFill(['synced_to_email_at' => now()])->save();
                    $forwarded++;
                }
            }
        }

        Log::info('Imported Meta form leads', [
            'project_id' => $project->id,
            'imported' => $imported,
            'forwarded' => $forwarded,
            'skipped' => $skipped,
        ]);

        return ['imported' => $imported, 'forwarded' => $forwarded, 'skipped' => $skipped];
    }
}
