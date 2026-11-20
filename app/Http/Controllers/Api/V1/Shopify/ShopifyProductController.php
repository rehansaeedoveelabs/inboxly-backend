<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShopifyProductResource;
use App\Models\ShopifyProduct;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShopifyProductController extends Controller
{
    public function index(Request $request, Store $store): AnonymousResourceCollection
    {
        $this->authorize('viewShopifyCommerceSync', $store);

        $q = trim((string) $request->query('q', ''));
        $limit = max(1, min(50, (int) $request->query('limit', 25)));

        $query = ShopifyProduct::query()
            ->where('store_id', $store->getKey())
            ->with('store:id,domain,shopify_shop_domain')
            ->orderBy('title')
            ->orderBy('id');

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like) {
                $builder->where('title', 'like', $like)
                    ->orWhere('handle', 'like', $like)
                    ->orWhere('vendor', 'like', $like)
                    ->orWhere('product_type', 'like', $like);
            });
        }

        $status = trim((string) $request->query('status', ''));
        if ($status !== '') {
            $query->where('status', $status);
        }

        return ShopifyProductResource::collection(
            $query->cursorPaginate($limit)->withQueryString()
        );
    }
}
