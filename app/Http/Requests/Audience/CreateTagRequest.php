<?php

namespace App\Http\Requests\Audience;

use App\Models\Store;
use Illuminate\Validation\Validator;

class CreateTagRequest extends TagRequest
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
        return $this->tagRules(partial: false);
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->slugClash($v)];
    }
}
