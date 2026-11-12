<?php

namespace App\Http\Requests\Campaigns;

use App\Enums\CampaignChannel;
use App\Enums\CampaignStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCampaignsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', Rule::enum(CampaignStatus::class)],
            'channel' => ['sometimes', 'nullable', Rule::enum(CampaignChannel::class)],
        ];
    }
}
