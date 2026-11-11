<?php

namespace App\Http\Requests\Audience;

use Illuminate\Foundation\Http\FormRequest;

class ListContactExportsRequest extends FormRequest
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
        ];
    }
}
