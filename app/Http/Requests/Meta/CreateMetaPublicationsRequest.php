<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

class CreateMetaPublicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publishMeta', $this->route('campaign'));
    }

    public function rules(): array
    {
        return [
            'meta_page_ids' => ['required', 'array', 'min:1'],
            'meta_page_ids.*' => ['required', 'string', 'ulid'],
            'confirm_republish' => ['sometimes', 'boolean'],
        ];
    }
}
