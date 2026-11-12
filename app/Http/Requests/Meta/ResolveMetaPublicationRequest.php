<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveMetaPublicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publishMeta', $this->route('campaign'));
    }

    public function rules(): array
    {
        return [
            'resolution' => ['required', 'string', Rule::in(['mark_published', 'mark_failed'])],
            'acknowledge_duplicate_risk' => ['sometimes', 'boolean'],
            'acknowledge_verified_on_facebook' => ['sometimes', 'boolean'],
            'provider_post_id' => ['nullable', 'string', 'max:191'],
        ];
    }
}
