<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Models\ShopifyComplianceRequest;
use Illuminate\Support\Facades\Log;

/**
 * Redispatches eligible stranded compliance work.
 *
 * Eligible:
 * - `received` older than the configured age (e.g. dispatch failed after commit)
 * - `processing` with an expired (or legacy missing) lease
 *
 * Never redispatches `awaiting_policy`, `completed`, or live-lease `processing`.
 * This is recovery, not monitoring/alerting.
 */
class RecoverStrandedShopifyComplianceRequests
{
    public function __construct(
        private readonly AcceptShopifyComplianceWebhook $accept,
        private readonly RecordShopifyComplianceEvent $recordEvent,
    ) {}

    /**
     * @return array{received: int, expired_processing: int, dispatch_failures: int}
     */
    public function handle(?int $olderThanSeconds = null, ?int $limit = null): array
    {
        $olderThanSeconds = max(1, $olderThanSeconds ?? (int) config(
            'services.shopify.compliance_recover_received_older_than_seconds',
            60,
        ));
        $limit = max(1, $limit ?? (int) config('services.shopify.compliance_recover_limit', 50));
        $leaseSeconds = max(1, (int) config('services.shopify.compliance_processing_lease_seconds', 300));

        $remaining = $limit;
        $received = $this->recoverReceived($olderThanSeconds, $remaining);
        $remaining = max(0, $limit - $received['dispatched'] - $received['dispatch_failures']);
        $expired = $remaining > 0
            ? $this->recoverExpiredProcessing($leaseSeconds, $remaining)
            : ['dispatched' => 0, 'dispatch_failures' => 0];

        return [
            'received' => $received['dispatched'],
            'expired_processing' => $expired['dispatched'],
            'dispatch_failures' => $received['dispatch_failures'] + $expired['dispatch_failures'],
        ];
    }

    /**
     * @return array{dispatched: int, dispatch_failures: int}
     */
    private function recoverReceived(int $olderThanSeconds, int $limit): array
    {
        $cutoff = now()->subSeconds($olderThanSeconds);
        $dispatched = 0;
        $failures = 0;

        ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::Received)
            ->where('received_at', '<=', $cutoff)
            ->orderBy('received_at')
            ->limit($limit)
            ->get()
            ->each(function (ShopifyComplianceRequest $request) use (&$dispatched, &$failures) {
                if ($this->dispatchWithAudit($request, 'stranded_received')) {
                    $dispatched++;
                } else {
                    $failures++;
                }
            });

        return ['dispatched' => $dispatched, 'dispatch_failures' => $failures];
    }

    /**
     * @return array{dispatched: int, dispatch_failures: int}
     */
    private function recoverExpiredProcessing(int $leaseSeconds, int $limit): array
    {
        $dispatched = 0;
        $failures = 0;
        $legacyCutoff = now()->subSeconds($leaseSeconds);

        ShopifyComplianceRequest::query()
            ->where('status', ShopifyComplianceStatus::Processing)
            ->where(function ($query) use ($legacyCutoff) {
                $query->where(function ($q) {
                    $q->whereNotNull('processing_lease_expires_at')
                        ->where('processing_lease_expires_at', '<=', now());
                })->orWhere(function ($q) use ($legacyCutoff) {
                    // Pre-lease rows or crash before lease write: treat started_at + lease window as expiry.
                    $q->whereNull('processing_lease_expires_at')
                        ->whereNotNull('processing_started_at')
                        ->where('processing_started_at', '<=', $legacyCutoff);
                });
            })
            ->orderBy('processing_started_at')
            ->limit($limit)
            ->get()
            ->each(function (ShopifyComplianceRequest $request) use (&$dispatched, &$failures) {
                // Re-check in PHP so a race with a live claim is not redispatched from a stale collection.
                if (! $request->fresh()?->hasExpiredProcessingLease()) {
                    return;
                }

                if ($this->dispatchWithAudit($request, 'expired_processing_lease')) {
                    $dispatched++;
                } else {
                    $failures++;
                }
            });

        return ['dispatched' => $dispatched, 'dispatch_failures' => $failures];
    }

    private function dispatchWithAudit(ShopifyComplianceRequest $request, string $reason): bool
    {
        $this->recordEvent->handle(
            $request,
            ShopifyComplianceRequestEventType::RecoveryDispatched,
            meta: [
                'reason' => $reason,
                'previous_status' => $request->status->value,
                'checkpoint_keys' => array_keys(is_array($request->processing_checkpoint) ? $request->processing_checkpoint : []),
            ],
        );

        $ok = $this->accept->dispatchProcessing($request);

        if ($ok) {
            Log::info('Redispatched stranded Shopify compliance request.', [
                'compliance_request_id' => $request->getKey(),
                'reason' => $reason,
            ]);

            return true;
        }

        Log::error('Shopify compliance recovery dispatch failed; request left eligible for next run.', [
            'compliance_request_id' => $request->getKey(),
            'reason' => $reason,
        ]);

        return false;
    }
}
