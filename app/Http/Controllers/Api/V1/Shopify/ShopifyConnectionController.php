<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Actions\Shopify\DisconnectShopifyStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopify\DisconnectShopifyRequest;
use App\Http\Resources\StoreResource;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class ShopifyConnectionController extends Controller
{
    /**
     * Admin-only disconnect for a workspace store.
     * Full path: DELETE /api/v1/stores/{store}/shopify/connection
     */
    public function destroy(
        DisconnectShopifyRequest $request,
        Store $store,
        DisconnectShopifyStore $disconnectShopifyStore,
    ): JsonResponse {
        $result = $disconnectShopifyStore->handle($store);

        return response()->json([
            'data' => (new StoreResource($result['store']))->resolve($request),
            'meta' => [
                'remote_revoked' => $result['remote_revoked'],
            ],
        ]);
    }
}
