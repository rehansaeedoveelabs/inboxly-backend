<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceStatus;
use App\Models\ShopifyComplianceRequest;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Persists processing checkpoints only while the caller still owns the processing lease.
 */
class PersistShopifyComplianceCheckpoint
{
    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function handle(
        ShopifyComplianceRequest $request,
        string $leaseToken,
        array $checkpoint,
    ): ShopifyComplianceRequest {
        $updated = DB::transaction(function () use ($request, $leaseToken, $checkpoint) {
            /** @var ShopifyComplianceRequest|null $locked */
            $locked = ShopifyComplianceRequest::query()
                ->whereKey($request->getKey())
                ->where('status', ShopifyComplianceStatus::Processing)
                ->where('processing_lease_token', $leaseToken)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            $locked->forceFill([
                'processing_checkpoint' => $checkpoint,
            ])->save();

            return $locked->fresh();
        });

        if ($updated === null) {
            throw new RuntimeException('compliance_lease_lost');
        }

        return $updated;
    }
}
