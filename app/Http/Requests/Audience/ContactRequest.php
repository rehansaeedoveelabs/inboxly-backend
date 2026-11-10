<?php

namespace App\Http\Requests\Audience;

use App\Models\Contact;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared contact validation. Email/phone are normalised before the unique checks so
 * "A@X.com " and "a@x.com" are the same contact. Uniqueness includes soft-deleted contacts
 * because the database unique index does too.
 */
abstract class ContactRequest extends FormRequest
{
    abstract protected function store(): Store;

    protected function ignoredContact(): ?Contact
    {
        return null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if ($this->has('email')) {
            $normalized['email'] = Contact::normalizeEmail($this->input('email'));
        }

        if ($this->has('phone')) {
            $phone = $this->input('phone');
            $looksValid = is_string($phone) && preg_match('/^\+?[0-9\s().-]{6,32}$/', trim($phone));

            // Invalid input is left untouched so the regex rule reports it.
            $normalized['phone'] = $looksValid ? Contact::normalizePhone($phone) : (blank($phone) ? null : $phone);
        }

        $this->merge($normalized);
    }

    /** @return array<string, mixed> */
    protected function contactRules(bool $partial): array
    {
        $store = $this->store();
        $ignore = $this->ignoredContact()?->getKey();
        $sometimes = $partial ? ['sometimes'] : [];

        $unique = fn (string $column) => Rule::unique('contacts', $column)
            ->where('store_id', $store->getKey())
            ->ignore($ignore);

        return [
            'email' => [...$sometimes, 'nullable', 'string', 'email:rfc', 'max:255', $unique('email')],
            'phone' => [...$sometimes, 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9]{6,32}$/', $unique('phone')],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:16'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
