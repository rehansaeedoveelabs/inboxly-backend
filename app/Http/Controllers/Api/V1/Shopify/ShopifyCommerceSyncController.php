<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Actions\Shopify\StartShopifyCommerceSync;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopify\StartShopifyCommerceSyncRequest;
use App\Http\Resources\ShopifySyncRunResource;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProduct;
use App\Models\ShopifyCustomerIdentity;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShopifyCommerceSyncController extends Controller
{
    public function show(Store $store): JsonResponse
    {
        $this->authorize('viewShopifyCommerceSync', $store);

        $latest = ShopifySyncRun::query()
            ->where('store_id', $store->getKey())
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'data' => [
                'summary' => $store->shopifySyncSummary(),
                'latest_run' => $latest !== null ? (new ShopifySyncRunResource($latest))->resolve() : null,
                'counts' => [
                    'customer_identities' => ShopifyCustomerIdentity::query()
                        ->where('store_id', $store->getKey())
                        ->count(),
                    'products' => ShopifyProduct::query()
                        ->where('store_id', $store->getKey())
                        ->count(),
                    'orders' => ShopifyOrder::query()
                        ->where('store_id', $store->getKey())
                        ->count(),
                ],
            ],
        ]);
    }

    public function store(
        StartShopifyCommerceSyncRequest $request,
        Store $store,
        StartShopifyCommerceSync $start,
    ): JsonResponse {
        try {
            $run = $start->handle($store, $request->resources());
        } catch (ConflictHttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return (new ShopifySyncRunResource($run))
            ->response()
            ->setStatusCode(202);
    }
}
