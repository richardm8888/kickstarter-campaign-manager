<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationManager;
use App\Integrations\Providers\MailerLiteIntegration;
use App\Models\Project;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Lets a creator choose which MailerLite group imported contacts join,
 * so automations and segments can be built around it.
 */
class MailerLiteGroupController extends Controller
{
    use AuthorizesRequests;

    public function index(Project $project, IntegrationManager $integrations): JsonResponse
    {
        $this->authorize('view', $project);

        $mailer = $integrations->for($project, 'mailerlite');
        $record = $project->integrations()->where('provider', 'mailerlite')->first();

        if (! $mailer instanceof MailerLiteIntegration || $record === null || ! $record->isConnected()) {
            return response()->json(['connected' => false, 'groups' => [], 'group_id' => null]);
        }

        try {
            $groups = $mailer->groups();
        } catch (Throwable $e) {
            return response()->json([
                'connected' => true,
                'groups' => [],
                'group_id' => $record->settings['group_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'connected' => true,
            'groups' => $groups,
            'group_id' => $record->settings['group_id'] ?? null,
        ]);
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $this->authorize('update', $project);

        $validated = $request->validate([
            // Null clears it: contacts then land in no group at all.
            'group_id' => ['present', 'nullable', 'string', 'max:64'],
        ]);

        $record = $project->integrations()->where('provider', 'mailerlite')->firstOrFail();

        $record->update([
            'settings' => [...$record->settings ?? [], 'group_id' => $validated['group_id']],
        ]);

        return response()->json([
            'group_id' => $validated['group_id'],
            'message' => $validated['group_id']
                ? 'New contacts will join this group.'
                : 'Contacts will no longer be added to a group.',
        ]);
    }
}
