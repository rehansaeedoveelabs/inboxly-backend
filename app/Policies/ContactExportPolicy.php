<?php

namespace App\Policies;

use App\Models\ContactExport;
use App\Models\User;

class ContactExportPolicy
{
    public function view(User $user, ContactExport $contactExport): bool
    {
        return $this->canManage($user, $contactExport);
    }

    public function download(User $user, ContactExport $contactExport): bool
    {
        return $this->canManage($user, $contactExport);
    }

    private function canManage(User $user, ContactExport $contactExport): bool
    {
        $store = $contactExport->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
