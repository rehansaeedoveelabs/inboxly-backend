<?php

namespace App\Http\Requests\Shopify;

use Illuminate\Foundation\Http\FormRequest;

class DisconnectShopifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $store = $this->route('store');

        return $this->user()?->can('disconnectShopify', $store) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
