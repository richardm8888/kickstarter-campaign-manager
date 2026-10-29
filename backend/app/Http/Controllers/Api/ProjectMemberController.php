<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Who can work on a project.
 *
 * Invitations are links, not emails: the owner copies the link and sends
 * it however they already talk to the person. That works whether or not
 * the server can send mail, and never claims an email went out when it
 * did not.
 */
class ProjectMemberController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        $isOwner = $project->isOwnedBy($request->user());
        $owner = $project->user()->first(['id', 'name', 'email']);

        return response()->json([
            'is_owner' => $isOwner,
            'owner' => $owner,
            'members' => $project->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email']),
            // Only the owner sees pending invitations, and only the owner can
            // copy their links again, because a link is a way in.
            'invitations' => $isOwner
                ? $project->invitations()->pending()->latest()->get()
                    ->map(fn (ProjectInvitation $invitation) => $this->present($invitation))
                : [],
        ]);
    }

    public function invite(Request $request, Project $project): JsonResponse
    {
        $this->authorize('manageMembers', $project);

        $email = Str::lower(trim($request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ])['email']));

        $existing = User::whereRaw('lower(email) = ?', [$email])->first();

        if ($existing !== null && $project->hasMember($existing)) {
            return response()->json(['message' => "{$existing->name} already has access."], 422);
        }

        // One live link per person: inviting again refreshes it rather than
        // leaving several working links to the same project lying around.
        $invitation = $project->invitations()->pending()->where('email', $email)->first()
            ?? $project->invitations()->make(['email' => $email]);

        $invitation->fill([
            'token' => Str::random(48),
            'invited_by' => $request->user()->id,
            'expires_at' => now()->addDays(ProjectInvitation::VALID_DAYS),
        ])->save();

        return response()->json(['invitation' => $this->present($invitation)], 201);
    }

    public function revoke(Project $project, ProjectInvitation $invitation): JsonResponse
    {
        $this->authorize('manageMembers', $project);
        abort_unless($invitation->project_id === $project->id, 404);

        $invitation->delete();

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    /** The owner removes someone, or a member leaves. */
    public function remove(Request $request, Project $project, User $user): JsonResponse
    {
        $isSelf = $request->user()->id === $user->id;

        abort_unless($isSelf ? $project->hasMember($user) : $request->user()->can('manageMembers', $project), 403);
        abort_if($project->isOwnedBy($user), 422, 'The owner cannot be removed from their own project.');

        $project->members()->detach($user->id);

        return response()->json(['message' => $isSelf ? 'You have left the project.' : "{$user->name} no longer has access."]);
    }

    /** @return array<string, mixed> */
    private function present(ProjectInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at->toIso8601String(),
            // The browser builds the link from the address it is on, so it is
            // right however the server's own URL is configured.
            'token' => $invitation->token,
        ];
    }
}
