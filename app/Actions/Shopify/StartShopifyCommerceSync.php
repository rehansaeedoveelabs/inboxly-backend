<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifySyncResource;
use App\Enums\ShopifySyncResourceStatus;
use App\Enums\ShopifySyncRunStatus;
use App\Jobs\RunShopifyCommerceSyncJob;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Claim a new commerce sync run and queue processing.
 */
class StartShopifyCommerceSync
{
    /**
     * @param  list<ShopifySyncResource>|null  $resources
     */
    public function handle(Store $store, ?array $resources = null): ShopifySyncRun
    {
        if (! $store->isConnected() || ! $store->hasShopifyCredentials()) {
            throw ValidationException::withMessages([
                'store' => ['Connect Shopify before starting a commerce sync.'],
            ]);
        }

        $requested = $resources === null || $resources === []
            ? ShopifySyncResource::all()
            : array_values($resources);

        $missingForRequest = [];
        foreach ($requested as $resource) {
            $scope = $resource->requiredScope();
            if (! $store->hasShopifyScope($scope)) {
                $missingForRequest[] = $scope;
            }
        }
        $missingForRequest = array_values(array_unique($missingForRequest));
        if ($missingForRequest !== []) {
            throw ValidationException::withMessages([
                'resources' => [
                    'Missing Shopify scopes for sync: '.implode(', ', $missingForRequest).'. Reconnect Shopify to grant them.',
                ],
            ]);
        }

        $active = ShopifySyncRun::query()
            ->where('store_id', $store->getKey())
            ->whereIn('status', [ShopifySyncRunStatus::Pending->value, ShopifySyncRunStatus::Running->value])
            ->where('lease_expires_at', '>', now())
            ->orderByDesc('created_at')
            ->first();

        if ($active !== null) {
            throw new ConflictHttpException('A Shopify commerce sync is already in progress for this store.');
        }

        $leaseSeconds = max(60, (int) config('services.shopify.sync_lease_seconds', 180));

        $run = DB::transaction(function () use ($store, $requested, $leaseSeconds) {
            $customers = in_array(ShopifySyncResource::Customers, $requested, true);
            $products = in_array(ShopifySyncResource::Products, $requested, true);
            $orders = in_array(ShopifySyncResource::Orders, $requested, true);

            return ShopifySyncRun::query()->create([
                'store_id' => $store->getKey(),
                'resources' => array_map(static fn (ShopifySyncResource $r) => $r->value, $requested),
                'status' => ShopifySyncRunStatus::Pending,
                'customers_status' => $customers ? ShopifySyncResourceStatus::Pending : ShopifySyncResourceStatus::Skipped,
                'products_status' => $products ? ShopifySyncResourceStatus::Pending : ShopifySyncResourceStatus::Skipped,
                'orders_status' => $orders ? ShopifySyncResourceStatus::Pending : ShopifySyncResourceStatus::Skipped,
                'customers_synced' => 0,
                'products_synced' => 0,
                'orders_synced' => 0,
                'started_at' => now(),
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
            ]);
        });

        RunShopifyCommerceSyncJob::dispatch($run->getKey());

        return $run->fresh() ?? $run;
    }
}
