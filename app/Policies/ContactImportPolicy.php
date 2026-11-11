<?php

namespace App\Policies;

use App\Models\ContactImport;
use App\Models\User;

class ContactImportPolicy
{
    public function view(User $user, ContactImport $contactImport): bool
    {
        $store = $contactImport->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
