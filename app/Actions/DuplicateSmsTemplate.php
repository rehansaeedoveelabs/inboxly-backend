<?php

namespace App\Actions;

use App\Enums\SmsTemplateStatus;
use App\Models\SmsTemplate;
use App\Models\User;

class DuplicateSmsTemplate
{
    public function handle(SmsTemplate $template, User $user): SmsTemplate
    {
        return $template->store->smsTemplates()->create([
            'created_by' => $user->getKey(),
            'name' => 'Copy of '.$template->name,
            'category' => $template->category,
            'status' => SmsTemplateStatus::Draft,
            'body' => $template->body,
        ]);
    }
}
