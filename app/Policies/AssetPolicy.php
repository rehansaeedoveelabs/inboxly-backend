<?php

namespace App\Policies;

use App\Models\Asset;
use App\Models\User;

class AssetPolicy
{
    public function view(User $user, Asset $asset): bool
    {
        return $this->canManage($user, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->canManage($user, $asset);
    }

    private function canManage(User $user, Asset $asset): bool
    {
        $store = $asset->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
