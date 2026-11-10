<?php

namespace App\Http\Requests\Audience;

use Illuminate\Foundation\Http\FormRequest;

class StartContactImportRequest extends FormRequest
{
    public const MAX_ROWS = 1000;

    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->route('store'));
    }

    /**
     * Only the shape is checked here (and that each row has an email); per-row problems such as
     * a malformed email are recorded on the import as failed rows instead of rejecting the batch.
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'array', 'min:1', 'max:'.self::MAX_ROWS],
            'rows.*' => ['array'],
            'rows.*.email' => ['required'],
            // Whitelisted keys only (validated() drops everything else); values are checked per row by the job.
            'rows.*.first_name' => ['nullable'],
            'rows.*.last_name' => ['nullable'],
            'rows.*.phone' => ['nullable'],
            'rows.*.locale' => ['nullable'],
            'rows.*.timezone' => ['nullable'],
            'rows.*.source' => ['nullable'],
            'rows.*.metadata' => ['nullable', 'array'],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'max:255', 'regex:/[\pL\pN]/u'],
            'filename' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
