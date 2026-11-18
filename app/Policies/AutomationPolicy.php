<?php

namespace App\Policies;

use App\Models\Automation;
use App\Models\User;

/**
 * Access is derived from the owning store's workspace membership.
 */
class AutomationPolicy
{
    public function view(User $user, Automation $automation): bool
    {
        return $this->canManage($user, $automation);
    }

    public function update(User $user, Automation $automation): bool
    {
        return $this->canManage($user, $automation);
    }

    public function activate(User $user, Automation $automation): bool
    {
        return $this->canManage($user, $automation);
    }

    public function pause(User $user, Automation $automation): bool
    {
        return $this->canManage($user, $automation);
    }

    public function archive(User $user, Automation $automation): bool
    {
        return $this->canManage($user, $automation);
    }

    private function canManage(User $user, Automation $automation): bool
    {
        $store = $automation->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
