<?php

namespace App\Policies;

use App\Models\Form;
use App\Models\User;

class FormPolicy
{
    public function view(User $user, Form $form): bool
    {
        return $this->canManage($user, $form);
    }

    public function update(User $user, Form $form): bool
    {
        return $this->canManage($user, $form);
    }

    public function delete(User $user, Form $form): bool
    {
        return $this->canManage($user, $form);
    }

    private function canManage(User $user, Form $form): bool
    {
        $store = $form->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
