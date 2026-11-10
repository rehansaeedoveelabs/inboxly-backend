<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

/**
 * Two roles only: admin and marketer. Marketers can read; admins can manage.
 */
class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function viewMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function viewStores(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function manageSettings(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function inviteMembers(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    public function createStore(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
