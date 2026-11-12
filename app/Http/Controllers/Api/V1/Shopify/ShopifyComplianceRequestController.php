<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Actions\Shopify\RetryShopifyComplianceRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopify\ListShopifyComplianceRequestsRequest;
use App\Http\Requests\Shopify\RetryShopifyComplianceRequestFormRequest;
use App\Http\Resources\ShopifyComplianceRequestResource;
use App\Models\ShopifyComplianceRequest;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;

class ShopifyComplianceRequestController extends Controller
{
    private const DEFAULT_LIMIT = 25;

    public function index(ListShopifyComplianceRequestsRequest $request, Store $store): AnonymousResourceCollection
    {
        $limit = (int) ($request->validated('limit') ?? self::DEFAULT_LIMIT);

        $requests = ShopifyComplianceRequest::query()
            ->where('store_id', $store->getKey())
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->cursorPaginate($limit)
            ->withQueryString();

        return ShopifyComplianceRequestResource::collection($requests);
    }

    public function show(Store $store, ShopifyComplianceRequest $complianceRequest): ShopifyComplianceRequestResource|JsonResponse
    {
        $this->authorize('viewShopifyCompliance', $store);

        if ($complianceRequest->store_id !== $store->getKey()) {
            abort(404);
        }

        return new ShopifyComplianceRequestResource($complianceRequest);
    }

    public function retry(
        RetryShopifyComplianceRequestFormRequest $request,
        Store $store,
        ShopifyComplianceRequest $complianceRequest,
        RetryShopifyComplianceRequest $retry,
    ): JsonResponse {
        if ($complianceRequest->store_id !== $store->getKey()) {
            abort(404);
        }

        try {
            $updated = $retry->handle($complianceRequest, $request->user());
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'compliance_retry_not_allowed') {
                return response()->json([
                    'message' => 'Only failed compliance requests can be retried.',
                ], 409);
            }

            throw $e;
        }

        return (new ShopifyComplianceRequestResource($updated))->response()->setStatusCode(202);
    }
}
