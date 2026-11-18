<?php

namespace App\Http\Requests\Automations;

use App\Enums\AutomationStepType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('automation'));
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'steps' => ['sometimes', 'array', 'min:1'],
            'steps.*.type' => ['required_with:steps', Rule::enum(AutomationStepType::class)],
            'steps.*.config' => ['nullable', 'array'],
            'steps.*.config.delay_minutes' => ['nullable', 'integer', 'min:0', 'max:525600'],
            'steps.*.config.subject' => ['nullable', 'string', 'max:255'],
            'steps.*.config.body' => ['nullable', 'string', 'max:100000'],
            'steps.*.config.html_body' => ['nullable', 'string', 'max:200000'],
            'steps.*.config.predicate' => ['nullable', 'string', Rule::in(['email_subscribed', 'has_tag'])],
            'steps.*.config.tag_id' => ['nullable', 'string', 'max:26'],
            'steps.*.config.on_true_goto' => ['nullable'],
            'steps.*.config.on_false_goto' => ['nullable'],
        ];
    }
}
