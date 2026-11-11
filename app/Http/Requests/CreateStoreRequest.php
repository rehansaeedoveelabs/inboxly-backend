<?php

namespace App\Http\Requests;

use App\Enums\StorePlatform;
use App\Support\Shopify\ShopifyDomain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createStore', $this->route('workspace'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('is_demo')) {
            // Demo stores do not persist a Shopify domain.
            $this->merge(['domain' => null]);

            return;
        }

        if (! is_string($this->domain)) {
            return;
        }

        try {
            $this->merge(['domain' => ShopifyDomain::normalize($this->domain)]);
        } catch (InvalidArgumentException) {
            // Leave the raw value so validation can reject it with a clear message.
            $domain = mb_strtolower(trim($this->domain));
            $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
            $this->merge(['domain' => rtrim($domain, '/')]);
        }
    }

    public function rules(): array
    {
        $workspace = $this->route('workspace');

        return [
            'name' => ['required', 'string', 'max:255'],
            'domain' => [
                Rule::requiredIf(fn () => ! $this->boolean('is_demo')),
                'nullable',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->boolean('is_demo')) {
                        return;
                    }

                    if (! is_string($value) || ! ShopifyDomain::isValid($value)) {
                        $fail('The domain must be a valid *.myshopify.com hostname.');
                    }
                },
                Rule::unique('stores', 'domain')
                    ->where('workspace_id', $workspace->getKey())
                    ->whereNull('deleted_at'),
            ],
            'platform' => ['sometimes', Rule::enum(StorePlatform::class)],
            'timezone' => ['sometimes', 'timezone:all'],
            'currency' => ['sometimes', 'string', 'size:3', 'alpha'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'is_demo' => ['sometimes', 'boolean'],
        ];
    }
}
