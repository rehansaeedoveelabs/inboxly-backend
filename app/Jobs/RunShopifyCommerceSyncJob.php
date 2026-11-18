<?php

namespace App\Jobs;

use App\Actions\Shopify\SyncShopifyCommerceResourcePage;
use App\Enums\ShopifySyncResource;
use App\Enums\ShopifySyncResourceStatus;
use App\Enums\ShopifySyncRunStatus;
use App\Exceptions\ShopifyAdminGraphqlException;
use App\Exceptions\ShopifyCredentialException;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use App\Services\Shopify\ShopifyCredentialService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunShopifyCommerceSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 120, 180];
    }

    public function __construct(public readonly string $syncRunId) {}

    public function handle(
        ShopifyCredentialService $credentials,
        SyncShopifyCommerceResourcePage $syncPage,
    ): void {
        $run = ShopifySyncRun::query()->find($this->syncRunId);
        if ($run === null) {
            return;
        }

        if (in_array($run->status, [ShopifySyncRunStatus::Succeeded, ShopifySyncRunStatus::Failed, ShopifySyncRunStatus::Partial], true)
            && $run->finished_at !== null) {
            return;
        }

        $store = Store::query()->find($run->store_id);
        if ($store === null || $store->trashed() || ! $store->hasShopifyCredentials()) {
            $this->failRun($run, 'store_unavailable', 'Store is unavailable for Shopify sync.');

            return;
        }

        $leaseSeconds = max(60, (int) config('services.shopify.sync_lease_seconds', 180));
        $run->forceFill([
            'status' => ShopifySyncRunStatus::Running,
            'lease_expires_at' => now()->addSeconds($leaseSeconds),
            'started_at' => $run->started_at ?? now(),
        ])->save();

        try {
            $accessToken = $credentials->ensureValidAccessToken($store);
        } catch (ShopifyCredentialException $e) {
            $this->failRun($run, $e->reasonCode, $e->getMessage());

            return;
        }

        foreach ($run->requestedResources() as $resource) {
            $status = $run->resourceStatus($resource);
            if (in_array($status, [ShopifySyncResourceStatus::Succeeded, ShopifySyncResourceStatus::Skipped, ShopifySyncResourceStatus::Failed], true)) {
                continue;
            }

            $run->setResourceStatus($resource, ShopifySyncResourceStatus::Running);
            $run->save();

            try {
                $result = $syncPage->handle($store->fresh() ?? $store, $run, $resource, $accessToken);
            } catch (ShopifyAdminGraphqlException $e) {
                $run->setResourceStatus($resource, ShopifySyncResourceStatus::Failed);
                $run->error_code = $e->reasonCode;
                $run->error_message = mb_substr($e->getMessage(), 0, 1900);
                $run->save();

                if ($e->throttled || ! $e->permanent) {
                    $this->release(max(15, (int) ($this->backoff()[min($this->attempts() - 1, 3)] ?? 60)));

                    return;
                }

                // Permanent resource failure — continue other resources, finalize as partial/failed.
                continue;
            } catch (Throwable $e) {
                Log::error('Shopify commerce sync unexpected failure.', [
                    'sync_run_id' => $run->getKey(),
                    'resource' => $resource->value,
                    'message' => $e->getMessage(),
                ]);
                $run->setResourceStatus($resource, ShopifySyncResourceStatus::Failed);
                $run->error_code = 'sync_exception';
                $run->error_message = mb_substr($e->getMessage(), 0, 1900);
                $run->save();

                continue;
            }

            $run->addSyncedCount($resource, $result['upserted']);
            $run->setCursorFor($resource, $result['next_cursor']);

            if ($result['exhausted']) {
                $run->setResourceStatus($resource, ShopifySyncResourceStatus::Succeeded);
                $run->setCursorFor($resource, null);
                $run->lease_expires_at = now()->addSeconds($leaseSeconds);
                $run->save();

                continue;
            }

            // More pages remain — persist progress and re-dispatch.
            $run->setResourceStatus($resource, ShopifySyncResourceStatus::Pending);
            $run->lease_expires_at = now()->addSeconds($leaseSeconds);
            $run->save();

            self::dispatch($run->getKey());

            return;
        }

        $this->finalize($run->fresh() ?? $run);
    }

    private function finalize(ShopifySyncRun $run): void
    {
        $statuses = [];
        foreach ($run->requestedResources() as $resource) {
            $statuses[] = $run->resourceStatus($resource);
        }

        $anyFailed = in_array(ShopifySyncResourceStatus::Failed, $statuses, true);
        $anySucceeded = in_array(ShopifySyncResourceStatus::Succeeded, $statuses, true);
        $anyPending = in_array(ShopifySyncResourceStatus::Pending, $statuses, true)
            || in_array(ShopifySyncResourceStatus::Running, $statuses, true);

        if ($anyPending) {
            // Should not happen if loop completed; re-queue defensively.
            self::dispatch($run->getKey());

            return;
        }

        if ($anyFailed && $anySucceeded) {
            $status = ShopifySyncRunStatus::Partial;
        } elseif ($anyFailed) {
            $status = ShopifySyncRunStatus::Failed;
        } else {
            $status = ShopifySyncRunStatus::Succeeded;
            $run->error_code = null;
            $run->error_message = null;
        }

        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'lease_expires_at' => null,
        ])->save();
    }

    private function failRun(ShopifySyncRun $run, string $code, string $message): void
    {
        foreach ($run->requestedResources() as $resource) {
            if ($run->resourceStatus($resource) !== ShopifySyncResourceStatus::Skipped) {
                $run->setResourceStatus($resource, ShopifySyncResourceStatus::Failed);
            }
        }

        $run->forceFill([
            'status' => ShopifySyncRunStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 1900),
            'finished_at' => now(),
            'lease_expires_at' => null,
        ])->save();
    }
}
