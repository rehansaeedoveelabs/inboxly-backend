<?php

namespace App\Http\Requests\Audience;

use App\Models\Store;
use Illuminate\Validation\Validator;

/**
 * Renaming keeps the slug stable: segment rules reference tags by slug, so it must not drift.
 * The new name therefore only has to not collide with another tag's slug.
 */
class UpdateTagRequest extends TagRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tag'));
    }

    protected function store(): Store
    {
        return $this->route('tag')->store;
    }

    public function rules(): array
    {
        return $this->tagRules(partial: true);
    }

    public function after(): array
    {
        return [fn (Validator $v) => $this->slugClash($v, $this->route('tag'))];
    }
}
