<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

class StartMetaOAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('connectMeta', $this->route('store'));
    }

    public function rules(): array
    {
        return [];
    }
}
