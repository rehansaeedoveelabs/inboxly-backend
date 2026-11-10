<?php

namespace App\Policies;

use App\Models\Contact;
use App\Models\User;

/**
 * Access is derived from the owning store's workspace membership, so a record id from
 * another workspace is always denied.
 */
class ContactPolicy
{
    public function view(User $user, Contact $contact): bool
    {
        return $this->canManage($user, $contact);
    }

    public function update(User $user, Contact $contact): bool
    {
        return $this->canManage($user, $contact);
    }

    public function delete(User $user, Contact $contact): bool
    {
        return $this->canManage($user, $contact);
    }

    private function canManage(User $user, Contact $contact): bool
    {
        $store = $contact->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
