<?php

namespace App\Actions;

use App\Enums\EmailTemplateStatus;
use App\Models\EmailTemplate;
use App\Models\Store;
use App\Models\User;
use App\Support\EmailTemplates\EmailBlockSchema;
use App\Support\EmailTemplates\EmailDocumentRenderer;
use Illuminate\Validation\ValidationException;

class UpsertEmailTemplate
{
    public function __construct(private EmailDocumentRenderer $renderer) {}

    /**
     * @param  array{
     *     name: string,
     *     category?: string,
     *     status?: string|EmailTemplateStatus,
     *     document: array<string, mixed>
     * }  $data
     */
    public function create(Store $store, User $user, array $data): EmailTemplate
    {
        $document = $this->validatedDocument($data['document']);
        $status = $this->resolveStatus($data['status'] ?? EmailTemplateStatus::Draft);

        return $store->emailTemplates()->create([
            'created_by' => $user->getKey(),
            'name' => $data['name'],
            'category' => $data['category'] ?? 'general',
            'status' => $status,
            'document' => $document,
            'html' => $this->renderer->render($store, $document),
        ]);
    }

    /**
     * @param  array{
     *     name?: string,
     *     category?: string,
     *     status?: string|EmailTemplateStatus,
     *     document?: array<string, mixed>
     * }  $data
     */
    public function update(EmailTemplate $template, array $data): EmailTemplate
    {
        if (array_key_exists('name', $data)) {
            $template->name = $data['name'];
        }
        if (array_key_exists('category', $data)) {
            $template->category = $data['category'] ?? 'general';
        }
        if (array_key_exists('status', $data)) {
            $template->status = $this->resolveStatus($data['status']);
        }
        if (array_key_exists('document', $data)) {
            $document = $this->validatedDocument($data['document']);
            $template->document = $document;
            $template->html = $this->renderer->render($template->store, $document);
        }

        $template->save();

        return $template->refresh();
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function validatedDocument(array $document): array
    {
        $errors = EmailBlockSchema::validate($document);
        if ($errors !== []) {
            throw ValidationException::withMessages(['document' => $errors]);
        }

        return $document;
    }

    private function resolveStatus(EmailTemplateStatus|string $status): EmailTemplateStatus
    {
        return $status instanceof EmailTemplateStatus
            ? $status
            : EmailTemplateStatus::from($status);
    }
}
