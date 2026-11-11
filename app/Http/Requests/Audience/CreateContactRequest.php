<?php

namespace App\Http\Requests\Audience;

use App\Models\Store;

class CreateContactRequest extends ContactRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAudience', $this->store());
    }

    protected function store(): Store
    {
        return $this->route('store');
    }

    public function rules(): array
    {
        // Active-only uniqueness: soft-deleted matches are handled as 409 / restore.
        $rules = $this->contactRules(partial: false, uniqueIncludesTrashed: false);
        $rules['email'][] = 'required_without:phone';
        $rules['phone'][] = 'required_without:email';
        $rules['restore'] = ['sometimes', 'boolean'];

        return $rules;
    }
}
