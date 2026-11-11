<?php

namespace App\Http\Requests\Audience;

use App\Actions\CreateTag;
use App\Models\Store;
use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class TagRequest extends FormRequest
{
    abstract protected function store(): Store;

    /** @return array<string, mixed> */
    protected function tagRules(bool $partial): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /** Slugs are unique per store; report the clash on "name" since that is what the user typed. */
    protected function slugClash(Validator $validator, ?Tag $ignore = null): void
    {
        if (! $this->has('name') || $validator->errors()->has('name')) {
            return;
        }

        $slug = app(CreateTag::class)->slugFor((string) $this->input('name'));

        if ($slug === '') {
            $validator->errors()->add('name', 'The name must contain letters or numbers.');

            return;
        }

        $exists = Tag::where('store_id', $this->store()->getKey())
            ->where('slug', $slug)
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            $validator->errors()->add('name', 'A tag with this name already exists in the store.');
        }
    }
}
