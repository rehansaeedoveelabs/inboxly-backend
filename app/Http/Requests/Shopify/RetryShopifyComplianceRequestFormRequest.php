<?php

namespace App\Http\Requests\Shopify;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;

class RetryShopifyComplianceRequestFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Store $store */
        $store = $this->route('store');

        return $this->user()?->can('retryShopifyCompliance', $store) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}
