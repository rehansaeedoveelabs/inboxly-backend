<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyCustomerIdentityStatus;
use App\Enums\ShopifySyncResource;
use App\Exceptions\ShopifyAdminGraphqlException;
use App\Models\ShopifyCustomerIdentity;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProduct;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use App\Services\Shopify\ShopifyAdminGraphqlClient;
use App\Support\Shopify\ShopifyCommerceGraphqlQueries;
use Carbon\Carbon;

/**
 * Fetch and upsert one bounded page-batch for a sync resource.
 */
class SyncShopifyCommerceResourcePage
{
    public function __construct(private readonly ShopifyAdminGraphqlClient $graphql) {}

    /**
     * @return array{upserted: int, next_cursor: ?string, exhausted: bool}
     *
     * @throws ShopifyAdminGraphqlException
     */
    public function handle(
        Store $store,
        ShopifySyncRun $run,
        ShopifySyncResource $resource,
        string $accessToken,
    ): array {
        $shopDomain = (string) ($store->shopify_shop_domain ?: $store->domain);
        $pageSize = max(1, (int) config('services.shopify.sync_page_size', 50));
        $maxPages = max(1, (int) config('services.shopify.sync_max_pages_per_attempt', 5));
        $after = $run->cursorFor($resource);

        [$query, $extract] = $this->queryFor($resource);

        $page = $this->graphql->paginate(
            $shopDomain,
            $accessToken,
            $query,
            $extract,
            $after,
            $pageSize,
            $maxPages,
        );

        $upserted = match ($resource) {
            ShopifySyncResource::Customers => $this->upsertCustomers($store, $page['nodes']),
            ShopifySyncResource::Products => $this->upsertProducts($store, $page['nodes']),
            ShopifySyncResource::Orders => $this->upsertOrders($store, $page['nodes']),
        };

        return [
            'upserted' => $upserted,
            'next_cursor' => $page['next_cursor'],
            'exhausted' => $page['exhausted'],
        ];
    }

    /**
     * @return array{0: string, 1: callable(array<string, mixed>): array{nodes: list<array<string, mixed>>, hasNextPage: bool, endCursor: ?string}}
     */
    private function queryFor(ShopifySyncResource $resource): array
    {
        return match ($resource) {
            ShopifySyncResource::Customers => [
                ShopifyCommerceGraphqlQueries::CUSTOMERS,
                fn (array $data) => $this->extractConnection($data, 'customers'),
            ],
            ShopifySyncResource::Products => [
                ShopifyCommerceGraphqlQueries::PRODUCTS,
                fn (array $data) => $this->extractConnection($data, 'products'),
            ],
            ShopifySyncResource::Orders => [
                ShopifyCommerceGraphqlQueries::ORDERS,
                fn (array $data) => $this->extractConnection($data, 'orders'),
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{nodes: list<array<string, mixed>>, hasNextPage: bool, endCursor: ?string}
     */
    private function extractConnection(array $data, string $field): array
    {
        $connection = $data[$field] ?? null;
        if (! is_array($connection)) {
            return ['nodes' => [], 'hasNextPage' => false, 'endCursor' => null];
        }

        $edges = is_array($connection['edges'] ?? null) ? $connection['edges'] : [];
        $nodes = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $node = $edge['node'] ?? null;
            if (is_array($node)) {
                $nodes[] = $node;
            }
        }

        $pageInfo = is_array($connection['pageInfo'] ?? null) ? $connection['pageInfo'] : [];

        return [
            'nodes' => $nodes,
            'hasNextPage' => (bool) ($pageInfo['hasNextPage'] ?? false),
            'endCursor' => isset($pageInfo['endCursor']) && is_string($pageInfo['endCursor'])
                ? $pageInfo['endCursor']
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function upsertCustomers(Store $store, array $nodes): int
    {
        $now = now();
        $count = 0;

        foreach ($nodes as $node) {
            $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if ($gid === '' || ! str_starts_with($gid, 'gid://shopify/Customer/')) {
                continue;
            }

            $shopifyUpdatedAt = $this->parseTimestamp($node['updatedAt'] ?? null);

            ShopifyCustomerIdentity::query()->updateOrCreate(
                [
                    'store_id' => $store->getKey(),
                    'shopify_customer_gid' => $gid,
                ],
                [
                    // Never set contact_id / never store email/phone/name.
                    'status' => ShopifyCustomerIdentityStatus::Tracked,
                    'shopify_updated_at' => $shopifyUpdatedAt,
                    'last_synced_at' => $now,
                    'last_seen_at' => $now,
                    'sync_error_code' => null,
                    'shopify_deleted_at' => null,
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function upsertProducts(Store $store, array $nodes): int
    {
        $now = now();
        $count = 0;

        foreach ($nodes as $node) {
            $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if ($gid === '' || ! str_starts_with($gid, 'gid://shopify/Product/')) {
                continue;
            }

            $featured = is_array($node['featuredImage'] ?? null) ? $node['featuredImage'] : [];
            $variantsCount = is_array($node['variantsCount'] ?? null) ? $node['variantsCount'] : [];

            ShopifyProduct::query()->updateOrCreate(
                [
                    'store_id' => $store->getKey(),
                    'shopify_product_gid' => $gid,
                ],
                [
                    'title' => $this->nullableString($node['title'] ?? null),
                    'handle' => $this->nullableString($node['handle'] ?? null),
                    'status' => $this->nullableString($node['status'] ?? null),
                    'product_type' => $this->nullableString($node['productType'] ?? null),
                    'vendor' => $this->nullableString($node['vendor'] ?? null),
                    'shopify_updated_at' => $this->parseTimestamp($node['updatedAt'] ?? null),
                    'last_synced_at' => $now,
                    'metadata' => array_filter([
                        'featured_image_url' => $this->nullableString($featured['url'] ?? null),
                        'variant_count' => isset($variantsCount['count']) && is_numeric($variantsCount['count'])
                            ? (int) $variantsCount['count']
                            : null,
                    ], static fn ($v) => $v !== null),
                ],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function upsertOrders(Store $store, array $nodes): int
    {
        $now = now();
        $count = 0;

        foreach ($nodes as $node) {
            $gid = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if ($gid === '' || ! str_starts_with($gid, 'gid://shopify/Order/')) {
                continue;
            }

            $money = [];
            if (is_array($node['totalPriceSet'] ?? null) && is_array($node['totalPriceSet']['shopMoney'] ?? null)) {
                $money = $node['totalPriceSet']['shopMoney'];
            }

            $customer = is_array($node['customer'] ?? null) ? $node['customer'] : [];
            $customerGid = $this->nullableString($customer['id'] ?? null);
            if ($customerGid !== null && ! str_starts_with($customerGid, 'gid://shopify/Customer/')) {
                $customerGid = null;
            }

            $legacyId = $node['legacyResourceId'] ?? null;
            $orderNumber = is_numeric($legacyId) ? (int) $legacyId : null;

            ShopifyOrder::query()->updateOrCreate(
                [
                    'store_id' => $store->getKey(),
                    'shopify_order_gid' => $gid,
                ],
                [
                    'name' => $this->nullableString($node['name'] ?? null),
                    'order_number' => $orderNumber,
                    'financial_status' => $this->nullableString($node['displayFinancialStatus'] ?? null),
                    'fulfillment_status' => $this->nullableString($node['displayFulfillmentStatus'] ?? null),
                    'currency_code' => $this->nullableString($money['currencyCode'] ?? null),
                    'total_price' => isset($money['amount']) && is_numeric($money['amount'])
                        ? (string) $money['amount']
                        : null,
                    'shopify_customer_gid' => $customerGid,
                    'processed_at' => $this->parseTimestamp($node['processedAt'] ?? null),
                    'shopify_updated_at' => $this->parseTimestamp($node['updatedAt'] ?? null),
                    'last_synced_at' => $now,
                    'metadata' => null,
                ],
            );
            $count++;
        }

        return $count;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
