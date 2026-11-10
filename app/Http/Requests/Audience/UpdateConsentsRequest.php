<?php

namespace App\Http\Requests\Audience;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConsentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    public function rules(): array
    {
        return [
            'consents' => ['required', 'array', 'min:1', 'max:'.count(ConsentChannel::cases())],
            'consents.*.channel' => ['required', Rule::enum(ConsentChannel::class)],
            'consents.*.status' => ['required', Rule::enum(ConsentStatus::class)],
            'consents.*.source' => ['nullable', 'string', 'max:64'],
        ];
    }
}
