<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

class DisconnectMetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('disconnectMeta', $this->route('store'));
    }

    public function rules(): array
    {
        return [];
    }
}
