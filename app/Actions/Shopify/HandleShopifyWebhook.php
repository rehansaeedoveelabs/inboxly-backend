<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceTopic;
use App\Enums\StoreConnectionStatus;
use App\Models\ShopifyWebhookDelivery;
use App\Models\Store;
use App\Support\Shopify\ShopifyDomain;
use App\Support\Shopify\ShopifyHmac;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class HandleShopifyWebhook
{
    public function __construct(
        private readonly AcceptShopifyComplianceWebhook $acceptCompliance,
    ) {}

    /**
     * @param  array<string, string|null>  $headers
     * @return array{status: int, duplicate?: bool}
     */
    public function handle(string $rawBody, array $headers): array
    {
        $appSecret = (string) config('services.shopify.app_secret');
        $hmac = $headers['x-shopify-hmac-sha256'] ?? null;

        if (! ShopifyHmac::isValidWebhook($rawBody, $hmac, $appSecret)) {
            Log::notice('Rejected Shopify webhook with invalid HMAC.');

            return ['status' => 401];
        }

        // Shopify HTTPS deliveries use Content-Type: application/json (optional charset).
        // Missing Content-Type is allowed so HMAC-valid deliveries are not dropped by proxies.
        $contentType = $headers['content-type'] ?? null;
        if (is_string($contentType) && $contentType !== '' && ! $this->isJsonContentType($contentType)) {
            Log::notice('Rejected Shopify webhook with non-JSON Content-Type.');

            return ['status' => 400];
        }

        $topic = $headers['x-shopify-topic'] ?? null;
        $shopHeader = $headers['x-shopify-shop-domain'] ?? null;
        $webhookId = $headers['x-shopify-webhook-id'] ?? null;

        if (! is_string($topic) || $topic === ''
            || ! is_string($shopHeader) || $shopHeader === ''
            || ! is_string($webhookId) || $webhookId === '') {
            Log::notice('Rejected Shopify webhook with missing required headers.');

            return ['status' => 400];
        }

        try {
            $shopDomain = ShopifyDomain::normalize($shopHeader);
        } catch (InvalidArgumentException) {
            Log::notice('Rejected Shopify webhook with invalid shop domain.', [
                'topic' => $topic,
            ]);

            return ['status' => 400];
        }

        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ['status' => 400];
        }

        $store = Store::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->first();

        if (ShopifyComplianceTopic::tryFrom($topic) !== null) {
            return $this->acceptCompliance->handle(
                $rawBody,
                $topic,
                $shopDomain,
                $webhookId,
                $payload,
                $store,
            );
        }

        if (! $this->claimDelivery($webhookId, $topic, $shopDomain, $store?->getKey())) {
            return ['status' => 200, 'duplicate' => true];
        }

        match ($topic) {
            'app/uninstalled' => $this->handleUninstalled($store, $shopDomain),
            'app/scopes_update' => $this->handleScopesUpdate($store, $shopDomain, $payload),
            default => Log::info('Ignored unsupported Shopify webhook topic.', [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
            ]),
        };

        // Delivery acceptance only — not privacy-processing completion.
        ShopifyWebhookDelivery::query()
            ->where('webhook_id', $webhookId)
            ->update(['processed_at' => now()]);

        return ['status' => 200];
    }

    private function isJsonContentType(string $contentType): bool
    {
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json';
    }

    private function claimDelivery(string $webhookId, string $topic, string $shopDomain, ?string $storeId): bool
    {
        try {
            ShopifyWebhookDelivery::query()->create([
                'webhook_id' => $webhookId,
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'store_id' => $storeId,
            ]);

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062 || str_contains(mb_strtolower($e->getMessage()), 'unique');
    }

    private function handleUninstalled(?Store $store, string $shopDomain): void
    {
        if ($store === null) {
            Log::info('Shopify uninstall webhook for unknown shop.', [
                'shop_domain' => $shopDomain,
            ]);

            return;
        }

        DB::transaction(function () use ($store) {
            /** @var Store $locked */
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                return;
            }

            if ($locked->shopify_access_token === null
                && $locked->shopify_refresh_token === null
                && in_array($locked->connection_status, [
                    StoreConnectionStatus::Disconnected,
                    StoreConnectionStatus::Demo,
                ], true)) {
                return;
            }

            $locked->forceFill([
                'shopify_access_token' => null,
                'shopify_refresh_token' => null,
                'shopify_access_token_expires_at' => null,
                'shopify_refresh_token_expires_at' => null,
                'shopify_scopes' => [],
                'connection_status' => $locked->is_demo
                    ? StoreConnectionStatus::Demo
                    : StoreConnectionStatus::Disconnected,
            ])->save();
        });

        Log::info('Shopify app uninstalled; local credentials cleared.', [
            'store_id' => $store->getKey(),
            'shop_domain' => $shopDomain,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handleScopesUpdate(?Store $store, string $shopDomain, array $payload): void
    {
        if ($store === null) {
            Log::info('Shopify scopes_update webhook for unknown shop.', [
                'shop_domain' => $shopDomain,
            ]);

            return;
        }

        $current = $payload['current'] ?? null;
        if (! is_array($current)) {
            Log::notice('Shopify scopes_update payload missing current scopes.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $shopDomain,
            ]);

            return;
        }

        $scopes = array_values(array_filter(array_map(
            static fn ($scope) => is_string($scope) ? trim($scope) : '',
            $current,
        )));

        DB::transaction(function () use ($store, $scopes) {
            /** @var Store $locked */
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->first();
            if ($locked === null || $locked->is_demo) {
                return;
            }

            $locked->forceFill([
                'shopify_scopes' => $scopes,
            ])->save();
        });

        Log::info('Shopify scopes updated from webhook.', [
            'store_id' => $store->getKey(),
            'shop_domain' => $shopDomain,
            'scope_count' => count($scopes),
        ]);
    }
}
