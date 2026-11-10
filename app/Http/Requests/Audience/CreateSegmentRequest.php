<?php

namespace App\Http\Requests\Audience;

use App\Rules\ValidSegmentRules;
use Illuminate\Foundation\Http\FormRequest;

class CreateSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'rules' => ['required', 'array', new ValidSegmentRules],
        ];
    }
}
