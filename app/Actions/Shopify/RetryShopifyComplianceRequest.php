<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Models\ShopifyComplianceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RetryShopifyComplianceRequest
{
    public function __construct(
        private readonly AcceptShopifyComplianceWebhook $accept,
        private readonly RecordShopifyComplianceEvent $recordEvent,
    ) {}

    public function handle(ShopifyComplianceRequest $request, User $actor): ShopifyComplianceRequest
    {
        $retried = DB::transaction(function () use ($request, $actor) {
            /** @var ShopifyComplianceRequest|null $locked */
            $locked = ShopifyComplianceRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('compliance_request_missing');
            }

            if ($locked->status !== ShopifyComplianceStatus::Failed) {
                throw new RuntimeException('compliance_retry_not_allowed');
            }

            $locked->forceFill([
                'status' => ShopifyComplianceStatus::Received,
                'error_code' => null,
                'error_message' => null,
                'failed_at' => null,
                'processing_started_at' => null,
                'processing_lease_expires_at' => null,
                'processing_lease_token' => null,
                // Preserve processing_checkpoint so completed steps remain idempotent.
            ])->save();

            $this->recordEvent->handle(
                $locked,
                ShopifyComplianceRequestEventType::RetryRequested,
                $actor,
                ['previous_status' => ShopifyComplianceStatus::Failed->value],
            );

            return $locked->fresh() ?? $locked;
        });

        $this->accept->dispatchProcessing($retried);

        return $retried;
    }
}
