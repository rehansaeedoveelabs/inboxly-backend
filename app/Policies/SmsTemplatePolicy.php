<?php

namespace App\Policies;

use App\Models\SmsTemplate;
use App\Models\User;

class SmsTemplatePolicy
{
    public function view(User $user, SmsTemplate $smsTemplate): bool
    {
        return $this->canManage($user, $smsTemplate);
    }

    public function update(User $user, SmsTemplate $smsTemplate): bool
    {
        return $this->canManage($user, $smsTemplate);
    }

    public function delete(User $user, SmsTemplate $smsTemplate): bool
    {
        return $this->canManage($user, $smsTemplate);
    }

    public function duplicate(User $user, SmsTemplate $smsTemplate): bool
    {
        return $this->canManage($user, $smsTemplate);
    }

    private function canManage(User $user, SmsTemplate $smsTemplate): bool
    {
        $store = $smsTemplate->store;

        return $store !== null && $user->can('manageAudience', $store);
    }
}
