<?php

namespace App\Policies;

use App\Models\EmailTemplate;
use App\Models\User;

class EmailTemplatePolicy
{
    public function view(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManage($user, $emailTemplate);
    }

    public function update(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManage($user, $emailTemplate);
    }

    public function delete(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManage($user, $emailTemplate);
    }

    public function duplicate(User $user, EmailTemplate $emailTemplate): bool
    {
        return $this->canManage($user, $emailTemplate);
    }

    private function canManage(User $user, EmailTemplate $emailTemplate): bool
    {
        $store = $emailTemplate->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
