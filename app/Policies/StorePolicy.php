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

    /**
     * Connecting Shopify changes platform credentials — Admin only.
     */
    public function connectShopify(User $user, Store $store): bool
    {
        return $store->workspace->isAdmin($user);
    }

    /**
     * Disconnecting Shopify clears platform credentials — Admin only.
     */
    public function disconnectShopify(User $user, Store $store): bool
    {
        return $this->connectShopify($user, $store);
    }

    /**
     * Connecting Meta changes platform credentials — Admin only.
     */
    public function connectMeta(User $user, Store $store): bool
    {
        return $store->workspace->isAdmin($user);
    }

    /**
     * Disconnecting Meta clears platform credentials — Admin only.
     */
    public function disconnectMeta(User $user, Store $store): bool
    {
        return $this->connectMeta($user, $store);
    }

    /**
     * Viewing Meta connection status/pages — any workspace member.
     */
    public function viewMetaConnection(User $user, Store $store): bool
    {
        return $this->view($user, $store);
    }

    /**
     * Discovering/selecting Pages — any workspace member (marketing work).
     * Credential connect/disconnect remains admin-only.
     */
    public function manageMetaPages(User $user, Store $store): bool
    {
        return $this->manageAudience($user, $store);
    }
}
