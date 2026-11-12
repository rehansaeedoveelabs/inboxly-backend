<?php

namespace App\Http\Requests\Meta;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCampaignSocialContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('publishMeta', $this->route('campaign'));
    }

    public function rules(): array
    {
        return [
            'message' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'link' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'asset_id' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }
}
