<?php

namespace App\Http\Requests\Shopify;

use Illuminate\Foundation\Http\FormRequest;

class StartShopifyOAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('connectShopify', $this->route('store'));
    }

    public function rules(): array
    {
        // No client-supplied shop/domain — authorize host comes only from the store row.
        return [];
    }
}
