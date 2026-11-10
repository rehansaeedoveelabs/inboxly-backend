<?php

namespace App\Actions;

use App\Models\Store;
use App\Models\User;
use App\Models\UserWorkspaceContext;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class SetActiveStore
{
    /**
     * Validates membership and that the store belongs to the workspace,
     * then stores the user's active store for that workspace.
     */
    public function handle(User $user, Workspace $workspace, ?Store $store): UserWorkspaceContext
    {
        if (! $workspace->hasMember($user)) {
            throw new AuthorizationException('You are not a member of this workspace.');
        }

        if ($store !== null && $store->workspace_id !== $workspace->getKey()) {
            throw ValidationException::withMessages([
                'store' => ['The store does not belong to this workspace.'],
            ]);
        }

        return UserWorkspaceContext::updateOrCreate(
            ['user_id' => $user->getKey(), 'workspace_id' => $workspace->getKey()],
            ['active_store_id' => $store?->getKey()],
        );
    }
}
