<?php

namespace App\Http\Requests\Audience;

use App\Rules\ValidSegmentRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('segment'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rules' => ['sometimes', 'required', 'array', new ValidSegmentRules],
        ];
    }
}
