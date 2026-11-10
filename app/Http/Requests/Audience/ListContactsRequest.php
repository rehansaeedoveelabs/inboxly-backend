<?php

namespace App\Http\Requests\Audience;

use App\Enums\ConsentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'consent_email' => ['sometimes', 'nullable', Rule::enum(ConsentStatus::class)],
        ];
    }
}
