<?php

namespace App\Http\Requests\Automations;

use App\Enums\AutomationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAutomationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(AutomationStatus::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
