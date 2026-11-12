<?php

namespace App\Http\Requests\Shopify;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;

class ListShopifyComplianceRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Store $store */
        $store = $this->route('store');

        return $this->user()?->can('viewShopifyCompliance', $store) ?? false;
    }

    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
