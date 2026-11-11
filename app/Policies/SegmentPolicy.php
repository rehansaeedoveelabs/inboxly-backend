<?php

namespace App\Policies;

use App\Models\Segment;
use App\Models\User;

/**
 * Access is derived from the owning store's workspace membership, so a record id from
 * another workspace is always denied.
 */
class SegmentPolicy
{
    public function view(User $user, Segment $segment): bool
    {
        return $this->canManage($user, $segment);
    }

    public function update(User $user, Segment $segment): bool
    {
        return $this->canManage($user, $segment);
    }

    public function delete(User $user, Segment $segment): bool
    {
        return $this->canManage($user, $segment);
    }

    private function canManage(User $user, Segment $segment): bool
    {
        $store = $segment->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
