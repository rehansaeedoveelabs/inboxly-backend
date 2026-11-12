<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Jobs\ProcessShopifyComplianceRequestJob;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyWebhookDelivery;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class AcceptShopifyComplianceWebhook
{
    public function __construct(
        private readonly RecordShopifyComplianceEvent $recordEvent,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, duplicate?: bool}
     */
    public function handle(
        string $rawBody,
        string $topic,
        string $shopDomain,
        string $webhookId,
        array $payload,
        ?Store $store,
    ): array {
        $complianceTopic = ShopifyComplianceTopic::tryFrom($topic);
        if ($complianceTopic === null) {
            return ['status' => 400];
        }

        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $customerId = isset($customer['id']) ? (string) $customer['id'] : null;
        $dataRequestId = null;
        if ($complianceTopic === ShopifyComplianceTopic::CustomersDataRequest) {
            $dataRequest = is_array($payload['data_request'] ?? null) ? $payload['data_request'] : [];
            $dataRequestId = isset($dataRequest['id']) ? (string) $dataRequest['id'] : null;
        }

        $email = isset($customer['email']) && is_string($customer['email']) ? $customer['email'] : null;
        $phone = isset($customer['phone']) && is_string($customer['phone']) ? $customer['phone'] : null;

        try {
            $request = DB::transaction(function () use (
                $rawBody,
                $complianceTopic,
                $shopDomain,
                $webhookId,
                $store,
                $customerId,
                $dataRequestId,
                $email,
                $phone,
            ) {
                try {
                    ShopifyWebhookDelivery::query()->create([
                        'webhook_id' => $webhookId,
                        'topic' => $complianceTopic->value,
                        'shop_domain' => $shopDomain,
                        'store_id' => $store?->getKey(),
                    ]);
                } catch (QueryException $e) {
                    if ($this->isUniqueViolation($e)) {
                        return null;
                    }

                    throw $e;
                }

                $request = ShopifyComplianceRequest::query()->create([
                    'store_id' => $store?->getKey(),
                    'workspace_id' => $store?->workspace_id,
                    'shop_domain' => $shopDomain,
                    'topic' => $complianceTopic,
                    'webhook_id' => $webhookId,
                    'shopify_customer_id' => $customerId,
                    'shopify_data_request_id' => $dataRequestId,
                    'payload_sha256' => hash('sha256', $rawBody),
                    'status' => ShopifyComplianceStatus::Received,
                    'processing_checkpoint' => [],
                    'legal_checklist' => [],
                    'attempt_count' => 0,
                    'received_at' => now(),
                ]);

                $this->storeCorrelation($request, $customerId, $email, $phone);

                $this->recordEvent->handle(
                    $request,
                    ShopifyComplianceRequestEventType::Received,
                    meta: ['topic' => $complianceTopic->value],
                );

                ShopifyWebhookDelivery::query()
                    ->where('webhook_id', $webhookId)
                    ->update(['processed_at' => now()]);

                return $request;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                Log::notice('Shopify compliance request logical duplicate rejected.', [
                    'topic' => $complianceTopic->value,
                    'shop_domain' => $shopDomain,
                ]);

                return ['status' => 200, 'duplicate' => true];
            }

            throw $e;
        }

        if ($request === null) {
            return ['status' => 200, 'duplicate' => true];
        }

        $this->dispatchProcessing($request);

        return ['status' => 200];
    }

    public function dispatchProcessing(ShopifyComplianceRequest $request): bool
    {
        try {
            ProcessShopifyComplianceRequestJob::dispatch($request->getKey())
                ->afterCommit();

            return true;
        } catch (Throwable $e) {
            Log::error('Failed to dispatch Shopify compliance processing job.', [
                'compliance_request_id' => $request->getKey(),
                'error_code' => class_basename($e),
            ]);

            return false;
        }
    }

    private function storeCorrelation(
        ShopifyComplianceRequest $request,
        ?string $customerId,
        ?string $email,
        ?string $phone,
    ): void {
        if ($customerId === null && $email === null && $phone === null) {
            return;
        }

        $disk = 'local';
        $path = 'compliance/'.$request->getKey().'/correlation.enc';
        $payload = Crypt::encryptString(json_encode([
            'shopify_customer_id' => $customerId,
            'email' => $email,
            'phone' => $phone,
        ], JSON_THROW_ON_ERROR));

        Storage::disk($disk)->put($path, $payload);

        $request->forceFill([
            'correlation_disk' => $disk,
            'correlation_path' => $path,
        ])->save();
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }
}
