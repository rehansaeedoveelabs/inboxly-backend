<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Shopify\ImportBrandAssetsFromShopify;
use App\Http\Controllers\Controller;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreBrandController extends Controller
{
    public function show(Store $store): StoreResource
    {
        $this->authorize('manageAudience', $store);

        return new StoreResource($store);
    }

    public function update(Request $request, Store $store): StoreResource
    {
        $this->authorize('manageAudience', $store);

        $data = $request->validate([
            'logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:26'],
            'logo_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imported_logo_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'colors' => ['sometimes', 'array'],
            'colors.accent' => ['sometimes', 'nullable', 'string', 'max:32'],
            'colors.dark' => ['sometimes', 'nullable', 'string', 'max:32'],
            'colors.light' => ['sometimes', 'nullable', 'string', 'max:32'],
            'colors.additional' => ['sometimes', 'array', 'max:6'],
            'colors.additional.*' => ['string', 'max:32'],
            'fonts' => ['sometimes', 'array'],
            'fonts.headings' => ['sometimes', 'nullable', 'string', 'max:120'],
            'fonts.body' => ['sometimes', 'nullable', 'string', 'max:120'],
            'social_links' => ['sometimes', 'array', 'max:20'],
            'social_links.*.network' => ['required_with:social_links', 'string', 'max:64'],
            'social_links.*.url' => ['required_with:social_links', 'string', 'max:500'],
        ]);

        if (! empty($data['logo_asset_id'])) {
            $ownsAsset = $store->assets()->whereKey($data['logo_asset_id'])->exists();
            if (! $ownsAsset) {
                abort(422, 'Logo asset must belong to this store.');
            }
        }

        $metadata = is_array($store->metadata) ? $store->metadata : [];
        $brand = is_array($metadata['brand'] ?? null) ? $metadata['brand'] : [];

        foreach (['logo_asset_id', 'logo_name', 'imported_logo_url', 'colors', 'fonts', 'social_links'] as $key) {
            if (array_key_exists($key, $data)) {
                $brand[$key] = $data[$key];
            }
        }

        $metadata['brand'] = $brand;
        $store->metadata = $metadata;
        $store->save();

        return new StoreResource($store->refresh());
    }

    public function importFromShopify(Store $store, ImportBrandAssetsFromShopify $import): JsonResponse
    {
        $this->authorize('manageAudience', $store);

        $result = $import->handle($store);

        return response()->json([
            'data' => (new StoreResource($store->refresh()))->resolve(),
            'source' => $result['source'],
            'notice' => $result['notice'],
        ]);
    }
}
