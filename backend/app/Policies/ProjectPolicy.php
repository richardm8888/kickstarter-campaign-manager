<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

/**
 * Members can do everything the owner can with the project's work.
 * Only the owner can delete it or decide who else has access, so a
 * project cannot be taken away from the person who created it.
 */
class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $project->hasMember($user);
    }

    public function update(User $user, Project $project): bool
    {
        return $project->hasMember($user);
    }

    public function delete(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $project->isOwnedBy($user);
    }
}
