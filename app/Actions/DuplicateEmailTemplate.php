<?php

namespace App\Actions;

use App\Enums\EmailTemplateStatus;
use App\Models\EmailTemplate;
use App\Models\User;

class DuplicateEmailTemplate
{
    public function handle(EmailTemplate $template, User $user): EmailTemplate
    {
        return $template->store->emailTemplates()->create([
            'created_by' => $user->getKey(),
            'name' => 'Copy of '.$template->name,
            'category' => $template->category,
            'status' => EmailTemplateStatus::Draft,
            'document' => $template->document,
            'html' => $template->html,
        ]);
    }
}
