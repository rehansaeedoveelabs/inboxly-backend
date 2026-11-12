<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

/**
 * Access is derived from the owning store's workspace membership, so a record id from
 * another workspace is always denied.
 */
class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function schedule(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function unschedule(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function duplicate(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function send(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    public function testSend(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    /**
     * Facebook Page publishing is separate from email send, but uses the same
     * audience management authorization (workspace members).
     */
    public function publishMeta(User $user, Campaign $campaign): bool
    {
        return $this->canManage($user, $campaign);
    }

    private function canManage(User $user, Campaign $campaign): bool
    {
        $store = $campaign->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
