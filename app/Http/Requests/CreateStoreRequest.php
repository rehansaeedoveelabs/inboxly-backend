<?php

namespace App\Http\Requests;

use App\Enums\StorePlatform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createStore', $this->route('workspace'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->domain)) {
            $domain = mb_strtolower(trim($this->domain));
            $domain = preg_replace('#^https?://#', '', $domain);
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
                'nullable', 'string', 'max:255',
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
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
