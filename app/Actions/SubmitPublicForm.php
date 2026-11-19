<?php

namespace App\Actions;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SubmitPublicForm
{
    public function __construct(
        private UpsertContact $upsertContact,
        private UpdateContactConsents $updateConsents,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  keyed by field id
     * @return array{submission: FormSubmission, contact_id: string|null}
     */
    public function handle(Form $form, array $payload, ?string $ip = null, ?string $userAgent = null): array
    {
        if ($form->status !== FormStatus::Enabled) {
            throw ValidationException::withMessages([
                'form' => ['This form is not accepting submissions.'],
            ]);
        }

        $definition = is_array($form->definition) ? $form->definition : [];
        $fields = is_array($definition['fields'] ?? null) ? $definition['fields'] : [];
        $normalized = [];
        $email = null;
        $phone = null;
        $consentEmail = false;
        $consentSms = false;

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $id = (string) ($field['id'] ?? '');
            $type = (string) ($field['type'] ?? '');
            if (! FormDefinitionSchema::isInputType($type)) {
                continue;
            }
            $required = ($field['required'] ?? false) === true;
            $raw = $payload[$id] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;

            if ($type === 'consent' || $type === 'checkbox') {
                $checked = $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
                if ($required && ! $checked) {
                    throw ValidationException::withMessages([
                        $id => ['Consent is required.'],
                    ]);
                }
                $normalized[$id] = $checked;
                $label = strtolower((string) ($field['label'] ?? ''));
                if ($checked && str_contains($label, 'sms')) {
                    $consentSms = true;
                } elseif ($checked) {
                    $consentEmail = true;
                }

                continue;
            }

            if ($required && (! is_string($value) || $value === '')) {
                throw ValidationException::withMessages([
                    $id => ['This field is required.'],
                ]);
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (mb_strlen($value) > 500) {
                throw ValidationException::withMessages([
                    $id => ['Value is too long.'],
                ]);
            }

            $normalized[$id] = $value;
            if ($type === 'email') {
                $email = mb_strtolower($value);
            }
            if ($type === 'phone') {
                $phone = $value;
            }
        }

        if ($email === null && $phone === null) {
            throw ValidationException::withMessages([
                'form' => ['An email or phone is required.'],
            ]);
        }

        return DB::transaction(function () use ($form, $normalized, $email, $phone, $consentEmail, $consentSms, $ip, $userAgent) {
            $contact = $this->upsertContact->handle($form->store, array_filter([
                'email' => $email,
                'phone' => $phone,
                'source' => 'form',
            ], fn ($v) => $v !== null));

            $consents = [];
            if ($consentEmail) {
                $consents[] = [
                    'channel' => ConsentChannel::Email->value,
                    'status' => ConsentStatus::Subscribed->value,
                    'source' => 'form',
                ];
            }
            if ($consentSms) {
                $consents[] = [
                    'channel' => ConsentChannel::Sms->value,
                    'status' => ConsentStatus::Subscribed->value,
                    'source' => 'form',
                ];
            }
            if ($consents !== []) {
                $this->updateConsents->handle($contact, $consents, 'form');
            }

            $submission = FormSubmission::query()->create([
                'form_id' => $form->getKey(),
                'store_id' => $form->store_id,
                'contact_id' => $contact->getKey(),
                'payload' => $normalized,
                'ip' => $ip,
                'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 512) : null,
            ]);

            $form->increment('submissions_count');

            return [
                'submission' => $submission,
                'contact_id' => $contact->getKey(),
            ];
        });
    }
}
