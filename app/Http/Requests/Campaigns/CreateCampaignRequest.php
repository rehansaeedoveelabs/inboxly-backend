<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        $storeId = $this->route('store')->getKey();

        return [
            'name' => ['required', 'string', 'max:255'],
            'channel' => ['required', Rule::enum(CampaignChannel::class)],
            'subject' => ['nullable', 'string', 'max:255'],
            'preview_text' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:100000'],
            'audience_type' => ['required', Rule::enum(CampaignAudienceType::class)],
            'segment_id' => [
                'nullable',
                'required_if:audience_type,segment',
                'string',
                Rule::exists('segments', 'id')->where(fn ($q) => $q
                    ->where('store_id', $storeId)
                    ->whereNull('deleted_at')),
            ],
        ];
    }
}
