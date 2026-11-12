<?php

namespace App\Http\Requests\Campaigns;

use Illuminate\Foundation\Http\FormRequest;

class ScheduleCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('schedule', $this->route('campaign'));
    }

    public function rules(): array
    {
        return [
            'scheduled_at' => ['required', 'date'],
        ];
    }
}
