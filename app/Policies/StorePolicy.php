<?php

namespace App\Policies;

use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function view(User $user, Store $store): bool
    {
        return $store->workspace->hasMember($user);
    }

    /**
     * Any workspace member can activate a store they can access.
     * (Per-store access restrictions can be added here later.)
     */
    public function activate(User $user, Store $store): bool
    {
        return $this->view($user, $store);
    }

    /**
     * Contacts, tags, segments and imports: any workspace member (Admin or Marketer).
     * Audience work is marketing work, so no admin-only gate here.
     */
    public function manageAudience(User $user, Store $store): bool
    {
        return $store->workspace->hasMember($user);
    }
}
