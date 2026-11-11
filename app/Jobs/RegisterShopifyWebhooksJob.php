<?php

namespace App\Jobs;

use App\Exceptions\ShopifyWebhookRegistrationException;
use App\Models\Store;
use App\Services\Shopify\ShopifyWebhookRegistrar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RegisterShopifyWebhooksJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * Bounded backoff (seconds) between transient retries.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 60, 180, 300];
    }

    public function __construct(public readonly string $storeId) {}

    public function handle(ShopifyWebhookRegistrar $registrar): void
    {
        $store = Store::query()->find($this->storeId);
        if ($store === null || $store->trashed() || ! $store->hasShopifyCredentials()) {
            return;
        }

        $this->persistRegistrationStatus($store, 'pending', [], 'registration_started');

        try {
            $confirmed = $registrar->registerRequiredTopics(
                $store,
                (string) $store->shopify_access_token,
            );
        } catch (ShopifyWebhookRegistrationException $e) {
            $this->persistRegistrationStatus(
                $store->fresh() ?? $store,
                'failed',
                [],
                $e->reasonCode,
            );

            Log::warning('Shopify webhook registration failed.', [
                'store_id' => $store->getKey(),
                'reason' => $e->reasonCode,
                'permanent' => $e->permanent,
                'attempt' => $this->attempts(),
            ]);

            if ($e->permanent) {
                // Permanent config/auth failures must not burn queue retries.
                return;
            }

            throw $e;
        }

        $this->persistRegistrationStatus(
            $store->fresh() ?? $store,
            'registered',
            $confirmed,
            'ok',
        );

        Log::info('Shopify webhook registration succeeded.', [
            'store_id' => $store->getKey(),
            'confirmed_topics' => $confirmed,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $store = Store::query()->find($this->storeId);
        if ($store === null) {
            return;
        }

        $reason = $exception instanceof ShopifyWebhookRegistrationException
            ? $exception->reasonCode
            : 'webhook_job_failed';

        $this->persistRegistrationStatus($store, 'failed', [], $reason);

        Log::error('Shopify webhook registration job exhausted retries.', [
            'store_id' => $this->storeId,
            'reason' => $reason,
        ]);
    }

    /**
     * Persist a minimal operator-visible status in existing store metadata JSON.
     * No secrets, tokens, or full authorization payloads.
     *
     * @param  list<string>  $topics
     */
    private function persistRegistrationStatus(
        Store $store,
        string $status,
        array $topics,
        string $reason,
    ): void {
        $metadata = is_array($store->metadata) ? $store->metadata : [];
        $host = null;
        $configured = trim((string) config('services.shopify.webhook_uri'));
        if ($configured !== '') {
            $host = parse_url($configured, PHP_URL_HOST);
            $host = is_string($host) ? $host : null;
        }

        $metadata['shopify_webhooks'] = [
            'status' => $status,
            'topics' => array_values($topics),
            'uri_host' => $host,
            'reason' => $reason,
            'updated_at' => now()->toIso8601String(),
        ];

        $store->forceFill(['metadata' => $metadata])->save();
    }
}
