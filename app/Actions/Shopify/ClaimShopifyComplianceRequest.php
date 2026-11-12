<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceStatus;
use App\Models\ShopifyComplianceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Atomically claims a compliance request for processing under a wall-clock lease.
 *
 * Eligible: `received`, `failed`, or `processing` with an expired/missing lease past the window.
 * Ineligible: `awaiting_policy`, `completed`, `partially_processed`, and `processing` with a live lease.
 * Checkpoints are never cleared.
 */
class ClaimShopifyComplianceRequest
{
    /**
     * @return array{request: ShopifyComplianceRequest, lease_token: string}|null
     */
    public function handle(string $complianceRequestId, int $jobTimeoutSeconds): ?array
    {
        $configured = (int) config('services.shopify.compliance_processing_lease_seconds', 300);
        // Never allow a lease shorter than the job timeout — prevents premature reclaim.
        $leaseSeconds = max($configured, $jobTimeoutSeconds + 60, 1);

        return DB::transaction(function () use ($complianceRequestId, $leaseSeconds) {
            /** @var ShopifyComplianceRequest|null $request */
            $request = ShopifyComplianceRequest::query()
                ->whereKey($complianceRequestId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                return null;
            }

            $eligibleStatus = in_array($request->status, [
                ShopifyComplianceStatus::Received,
                ShopifyComplianceStatus::Failed,
            ], true);

            if (! $eligibleStatus && ! $request->hasExpiredProcessingLease($leaseSeconds)) {
                return null;
            }

            $leaseToken = (string) Str::ulid();

            $request->forceFill([
                'status' => ShopifyComplianceStatus::Processing,
                'processing_started_at' => now(),
                'processing_lease_expires_at' => now()->addSeconds($leaseSeconds),
                'processing_lease_token' => $leaseToken,
                'attempt_count' => $request->attempt_count + 1,
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
                // processing_checkpoint intentionally preserved for idempotent replay.
            ])->save();

            $fresh = $request->fresh();

            if ($fresh === null) {
                return null;
            }

            return [
                'request' => $fresh,
                'lease_token' => $leaseToken,
            ];
        });
    }
}
