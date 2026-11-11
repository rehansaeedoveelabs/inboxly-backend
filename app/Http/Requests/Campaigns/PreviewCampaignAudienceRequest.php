<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PreviewCampaignAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        $storeId = $this->route('store')->getKey();

        return [
            'channel' => ['required', Rule::enum(CampaignChannel::class)],
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
