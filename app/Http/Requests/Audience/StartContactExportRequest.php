<?php

namespace App\Http\Requests\Audience;

use App\Enums\ConsentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartContactExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tag' => ['sometimes', 'nullable', 'string', 'max:255'],
            'consent_email' => ['sometimes', 'nullable', Rule::enum(ConsentStatus::class)],
        ];
    }
}
