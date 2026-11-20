<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ShopifyProduct */
class ShopifyProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $imageUrl = isset($metadata['featured_image_url']) && is_string($metadata['featured_image_url'])
            ? $metadata['featured_image_url']
            : null;
        $priceMin = isset($metadata['price_min']) && is_string($metadata['price_min'])
            ? $metadata['price_min']
            : null;
        $priceMax = isset($metadata['price_max']) && is_string($metadata['price_max'])
            ? $metadata['price_max']
            : null;
        $currency = isset($metadata['currency_code']) && is_string($metadata['currency_code'])
            ? $metadata['currency_code']
            : null;

        return [
            'id' => $this->getKey(),
            'shopify_product_gid' => $this->shopify_product_gid,
            'title' => $this->title,
            'handle' => $this->handle,
            'status' => $this->status,
            'product_type' => $this->product_type,
            'vendor' => $this->vendor,
            'featured_image_url' => $imageUrl,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'currency_code' => $currency,
            'product_url' => $this->store?->storefrontProductUrl($this->handle),
            'last_synced_at' => optional($this->last_synced_at)?->toIso8601String(),
        ];
    }
}
