<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

class SelectMetaPagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageMetaPages', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'page_ids' => ['required', 'array', 'min:1'],
            'page_ids.*' => ['required', 'string', 'max:64'],
            'default_page_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
