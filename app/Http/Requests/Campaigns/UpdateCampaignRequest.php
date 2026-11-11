<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignAudienceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('campaign'));
    }

    public function rules(): array
    {
        $storeId = $this->route('campaign')->store_id;

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'preview_text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:100000'],
            'audience_type' => ['sometimes', 'required', Rule::enum(CampaignAudienceType::class)],
            'segment_id' => [
                'nullable',
                'string',
                'required_if:audience_type,segment',
                Rule::exists('segments', 'id')->where(fn ($q) => $q
                    ->where('store_id', $storeId)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
