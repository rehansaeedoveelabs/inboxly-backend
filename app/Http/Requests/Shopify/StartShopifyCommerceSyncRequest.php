<?php

namespace App\Http\Requests\Shopify;

use App\Enums\ShopifySyncResource;
use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartShopifyCommerceSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Store $store */
        $store = $this->route('store');

        return $this->user()?->can('syncShopifyCommerce', $store) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resources' => ['sometimes', 'array', 'min:1'],
            'resources.*' => ['required', 'string', Rule::in(array_map(
                static fn (ShopifySyncResource $r) => $r->value,
                ShopifySyncResource::all(),
            ))],
        ];
    }

    /**
     * @return list<ShopifySyncResource>|null
     */
    public function resources(): ?array
    {
        $raw = $this->validated('resources');
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        $out = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $resource = ShopifySyncResource::tryFrom($value);
            if ($resource !== null) {
                $out[] = $resource;
            }
        }

        return $out === [] ? null : array_values($out);
    }
}
