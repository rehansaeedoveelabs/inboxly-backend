<?php

namespace App\Http\Requests\Audience;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttachTagsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    public function rules(): array
    {
        return [
            'tag_ids' => ['required', 'array', 'min:1', 'max:100'],
            // Tags must belong to the contact's store: a foreign tag id is a validation error.
            'tag_ids.*' => [
                'string', 'distinct',
                Rule::exists('tags', 'id')->where('store_id', $this->route('contact')->store_id),
            ],
        ];
    }
}
