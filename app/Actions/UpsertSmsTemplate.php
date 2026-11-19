<?php

namespace App\Actions;

use App\Enums\SmsTemplateStatus;
use App\Models\SmsTemplate;
use App\Models\Store;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class UpsertSmsTemplate
{
    /**
     * @param  array{
     *     name: string,
     *     category?: string,
     *     status?: string|SmsTemplateStatus,
     *     body: string
     * }  $data
     */
    public function create(Store $store, User $user, array $data): SmsTemplate
    {
        return $store->smsTemplates()->create([
            'created_by' => $user->getKey(),
            'name' => $data['name'],
            'category' => $data['category'] ?? 'promo',
            'status' => $this->resolveStatus($data['status'] ?? SmsTemplateStatus::Draft),
            'body' => $this->validatedBody($data['body']),
        ]);
    }

    /**
     * @param  array{
     *     name?: string,
     *     category?: string,
     *     status?: string|SmsTemplateStatus,
     *     body?: string
     * }  $data
     */
    public function update(SmsTemplate $template, array $data): SmsTemplate
    {
        if (array_key_exists('name', $data)) {
            $template->name = $data['name'];
        }
        if (array_key_exists('category', $data)) {
            $template->category = $data['category'] ?? 'promo';
        }
        if (array_key_exists('status', $data)) {
            $template->status = $this->resolveStatus($data['status']);
        }
        if (array_key_exists('body', $data)) {
            $template->body = $this->validatedBody($data['body']);
        }

        $template->save();

        return $template->refresh();
    }

    private function validatedBody(mixed $body): string
    {
        if (! is_string($body) || trim($body) === '') {
            throw ValidationException::withMessages([
                'body' => ['SMS body is required.'],
            ]);
        }
        if (mb_strlen($body) > 640) {
            throw ValidationException::withMessages([
                'body' => ['SMS body may be at most 640 characters.'],
            ]);
        }

        return $body;
    }

    private function resolveStatus(SmsTemplateStatus|string $status): SmsTemplateStatus
    {
        return $status instanceof SmsTemplateStatus
            ? $status
            : SmsTemplateStatus::from($status);
    }
}
