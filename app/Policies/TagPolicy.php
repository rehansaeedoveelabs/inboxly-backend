<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

/**
 * Access is derived from the owning store's workspace membership, so a record id from
 * another workspace is always denied.
 */
class TagPolicy
{
    public function view(User $user, Tag $tag): bool
    {
        return $this->canManage($user, $tag);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $this->canManage($user, $tag);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $this->canManage($user, $tag);
    }

    private function canManage(User $user, Tag $tag): bool
    {
        $store = $tag->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
