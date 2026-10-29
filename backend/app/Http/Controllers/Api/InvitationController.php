<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProjectInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The invited person's side: see what they were invited to, then join.
 */
class InvitationController extends Controller
{
    /**
     * Public, so the invite page can say who invited them to what before
     * they have an account. Shows nothing beyond that.
     */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->find($token);

        return response()->json([
            'project' => $invitation->project->only('id', 'name'),
            'invited_by' => $invitation->inviter->name,
            'email' => $invitation->email,
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $invitation = $this->find($token);
        $user = $request->user();
        $project = $invitation->project;

        // The owner opening their own link should not error, nor add a
        // duplicate membership.
        if (! $project->hasMember($user)) {
            $project->members()->attach($user->id);
        }

        $invitation->update(['accepted_at' => now()]);

        return response()->json(['project' => $project->only('id', 'name')]);
    }

    private function find(string $token): ProjectInvitation
    {
        $invitation = ProjectInvitation::with('project', 'inviter')->where('token', $token)->first();

        abort_if($invitation === null, 404, 'This invite link is not valid.');
        abort_unless($invitation->isUsable(), 410, 'This invite link has already been used or has expired. Ask for a new one.');

        return $invitation;
    }
}
