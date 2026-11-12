<?php

namespace App\Jobs;

use App\Actions\Shopify\ClaimShopifyComplianceRequest;
use App\Actions\Shopify\Compliance\ProcessCustomersDataRequest;
use App\Actions\Shopify\Compliance\ProcessCustomersRedact;
use App\Actions\Shopify\Compliance\ProcessShopRedact;
use App\Actions\Shopify\RecordShopifyComplianceEvent;
use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Models\ShopifyComplianceRequest;
use App\Support\Shopify\ShopifyComplianceCorrelation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessShopifyComplianceRequestJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    public function __construct(public readonly string $complianceRequestId) {}

    public function handle(
        ClaimShopifyComplianceRequest $claim,
        ProcessCustomersDataRequest $dataRequest,
        ProcessCustomersRedact $customersRedact,
        ProcessShopRedact $shopRedact,
        RecordShopifyComplianceEvent $recordEvent,
    ): void {
        $claimed = $claim->handle($this->complianceRequestId, $this->timeout);

        if ($claimed === null) {
            return;
        }

        $request = $claimed['request'];
        $leaseToken = $claimed['lease_token'];

        $recordEvent->handle($request, ShopifyComplianceRequestEventType::ProcessingStarted, meta: [
            'attempt' => $request->attempt_count,
        ]);

        try {
            $result = match ($request->topic) {
                ShopifyComplianceTopic::CustomersDataRequest => $dataRequest->handle($request),
                ShopifyComplianceTopic::CustomersRedact => $customersRedact->handle($request),
                ShopifyComplianceTopic::ShopRedact => $shopRedact->handle($request, $leaseToken),
            };

            $this->finalize($request, $leaseToken, $result, $recordEvent);
        } catch (Throwable $e) {
            $this->markFailed($request, $leaseToken, $e, $recordEvent);
            throw $e;
        }
    }

    /**
     * @param  array{status: ShopifyComplianceStatus, checkpoint: array<string, mixed>, legal_checklist: array<string, mixed>, inventory_summary: array<string, mixed>, clear_correlation?: bool}  $result
     */
    private function finalize(
        ShopifyComplianceRequest $request,
        string $leaseToken,
        array $result,
        RecordShopifyComplianceEvent $recordEvent,
    ): void {
        $status = $result['status'];
        $clearCorrelation = (bool) ($result['clear_correlation'] ?? false);

        $updates = [
            'status' => $status,
            'processing_checkpoint' => $result['checkpoint'],
            'legal_checklist' => $result['legal_checklist'],
            'inventory_summary' => $result['inventory_summary'],
            'error_code' => null,
            'error_message' => null,
        ];

        if ($status === ShopifyComplianceStatus::Completed) {
            $updates['completed_at'] = now();
            $updates['awaiting_policy_at'] = null;
        } elseif ($status === ShopifyComplianceStatus::AwaitingPolicy) {
            $updates['awaiting_policy_at'] = now();
            $updates['completed_at'] = null;
        } elseif ($status === ShopifyComplianceStatus::PartiallyProcessed) {
            $updates['completed_at'] = null;
        }

        if (! $this->transitionOwned($request, $leaseToken, $updates, $clearCorrelation)) {
            Log::warning('Shopify compliance finalize skipped; lease ownership lost.', [
                'compliance_request_id' => $request->getKey(),
            ]);

            return;
        }

        $eventType = match ($status) {
            ShopifyComplianceStatus::Completed => ShopifyComplianceRequestEventType::Completed,
            ShopifyComplianceStatus::AwaitingPolicy => ShopifyComplianceRequestEventType::AwaitingPolicy,
            ShopifyComplianceStatus::PartiallyProcessed => ShopifyComplianceRequestEventType::PartiallyProcessed,
            default => ShopifyComplianceRequestEventType::StepCompleted,
        };

        $recordEvent->handle($request->fresh() ?? $request, $eventType, meta: [
            'status' => $status->value,
        ]);
    }

    private function markFailed(
        ShopifyComplianceRequest $request,
        string $leaseToken,
        Throwable $e,
        RecordShopifyComplianceEvent $recordEvent,
    ): void {
        $code = class_basename($e);
        $message = $e->getMessage();
        if ($message === 'compliance_inventory_write_failed') {
            $code = 'inventory_write_failed';
        } elseif ($message === 'compliance_correlation_unreadable') {
            $code = 'correlation_unreadable';
        } elseif ($message === 'compliance_lease_lost') {
            $code = 'lease_lost';
        }

        // Keep correlation on failure so Admin retry / reclaim can rebuild a complete inventory.
        $saved = $this->transitionOwned($request, $leaseToken, [
            'status' => ShopifyComplianceStatus::Failed,
            'failed_at' => now(),
            'error_code' => substr($code, 0, 64),
            'error_message' => substr(match ($message) {
                'compliance_inventory_write_failed' => 'Inventory artifact could not be verified.',
                'compliance_correlation_unreadable' => 'Correlation artifact unreadable; refusing incomplete inventory.',
                'compliance_lease_lost' => 'Processing lease lost before step could be persisted.',
                default => 'Compliance processing failed.',
            }, 0, 500),
        ], clearCorrelation: false);

        if (! $saved) {
            Log::warning('Shopify compliance markFailed skipped; lease ownership lost.', [
                'compliance_request_id' => $request->getKey(),
                'error_code' => $code,
            ]);

            return;
        }

        $recordEvent->handle($request->fresh() ?? $request, ShopifyComplianceRequestEventType::Failed, meta: [
            'error_code' => $code,
        ]);

        Log::error('Shopify compliance processing failed.', [
            'compliance_request_id' => $request->getKey(),
            'error_code' => $code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function transitionOwned(
        ShopifyComplianceRequest $request,
        string $leaseToken,
        array $updates,
        bool $clearCorrelation = false,
    ): bool {
        $artifactToDelete = null;

        $ok = DB::transaction(function () use ($request, $leaseToken, $updates, $clearCorrelation, &$artifactToDelete) {
            /** @var ShopifyComplianceRequest|null $locked */
            $locked = ShopifyComplianceRequest::query()
                ->whereKey($request->getKey())
                ->where('status', ShopifyComplianceStatus::Processing)
                ->where('processing_lease_token', $leaseToken)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return false;
            }

            if ($clearCorrelation && (filled($locked->correlation_path) || filled($locked->correlation_disk))) {
                $artifactToDelete = [
                    'disk' => $locked->correlation_disk,
                    'path' => $locked->correlation_path,
                ];
                $updates['correlation_disk'] = null;
                $updates['correlation_path'] = null;
            }

            $locked->forceFill(array_merge($updates, [
                'processing_lease_expires_at' => null,
                'processing_lease_token' => null,
            ]))->save();

            return true;
        });

        if ($ok && is_array($artifactToDelete)) {
            ShopifyComplianceCorrelation::deleteFile(
                $artifactToDelete['disk'] ?? null,
                $artifactToDelete['path'] ?? null,
            );
        }

        return $ok;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Shopify compliance job exhausted retries.', [
            'compliance_request_id' => $this->complianceRequestId,
            'error_code' => $exception ? class_basename($exception) : 'unknown',
        ]);
    }
}
