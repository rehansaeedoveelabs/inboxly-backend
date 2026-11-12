<?php

namespace App\Services\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Exceptions\ShopifyCredentialException;
use App\Models\Store;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Boundary for obtaining a usable Shopify offline access token.
 *
 * Concurrent refreshes for the same store are serialized with a cache lock.
 * The lock is released before returning; the Shopify HTTP call is never made
 * inside a database transaction.
 */
class ShopifyCredentialService
{
    public function __construct(private readonly ShopifyOAuthClient $client) {}

    /**
     * Return a usable access token, refreshing and rotating credentials when needed.
     *
     * @throws ShopifyCredentialException
     */
    public function ensureValidAccessToken(Store $store): string
    {
        $store->refresh();

        if ($store->is_demo || $store->connection_status === StoreConnectionStatus::Demo) {
            throw new ShopifyCredentialException(
                'Demo stores do not have Shopify credentials.',
                'demo_store',
                permanent: true,
            );
        }

        if (! $store->hasShopifyCredentials()) {
            throw new ShopifyCredentialException(
                'This store has no usable Shopify credentials.',
                'credentials_missing',
                permanent: true,
            );
        }

        if (! $store->accessTokenNeedsRefresh()) {
            return (string) $store->shopify_access_token;
        }

        $lockSeconds = max(5, (int) config('services.shopify.token_refresh_lock_seconds', 20));
        $waitSeconds = max(1, (int) config('services.shopify.token_refresh_lock_wait_seconds', 10));
        $lock = Cache::lock($this->lockKey($store), $lockSeconds);

        try {
            return $lock->block($waitSeconds, function () use ($store) {
                return $this->refreshUnderLock($store->getKey());
            });
        } catch (LockTimeoutException $e) {
            Log::warning('Timed out waiting for Shopify credential refresh lock.', [
                'store_id' => $store->getKey(),
            ]);

            throw new ShopifyCredentialException(
                'Another credential refresh is already in progress for this store.',
                'token_refresh_lock_timeout',
                permanent: false,
                previous: $e,
            );
        }
    }

    /**
     * Query Shopify for the live granted access scope handles for this install.
     * Uses a refreshed access token. Does not change connection status or clear credentials.
     *
     * @return list<string>
     *
     * @throws ShopifyCredentialException
     */
    public function fetchLiveAccessScopes(Store $store): array
    {
        $accessToken = $this->ensureValidAccessToken($store);
        $shopDomain = (string) $store->fresh()?->shopify_shop_domain;

        if ($shopDomain === '') {
            throw new ShopifyCredentialException(
                'This store has no Shopify shop domain.',
                'shop_domain_missing',
                permanent: true,
            );
        }

        return $this->client->fetchGrantedAccessScopes($shopDomain, $accessToken);
    }

    /**
     * Force a refresh even when the access token is still within the skew window.
     * Intended for tests and explicit operational recovery — not request hot paths.
     *
     * @throws ShopifyCredentialException
     */
    public function refreshNow(Store $store): Store
    {
        $lockSeconds = max(5, (int) config('services.shopify.token_refresh_lock_seconds', 20));
        $waitSeconds = max(1, (int) config('services.shopify.token_refresh_lock_wait_seconds', 10));
        $lock = Cache::lock($this->lockKey($store), $lockSeconds);

        try {
            $lock->block($waitSeconds, function () use ($store) {
                $this->performRefresh($store->fresh() ?? $store, force: true);
            });
        } catch (LockTimeoutException $e) {
            throw new ShopifyCredentialException(
                'Another credential refresh is already in progress for this store.',
                'token_refresh_lock_timeout',
                permanent: false,
                previous: $e,
            );
        }

        return $store->fresh();
    }

    private function refreshUnderLock(string $storeId): string
    {
        $store = Store::query()->find($storeId);

        if ($store === null || $store->trashed()) {
            throw new ShopifyCredentialException(
                'The store no longer exists.',
                'store_missing',
                permanent: true,
            );
        }

        // Another request may have already rotated credentials while we waited.
        if (! $store->accessTokenNeedsRefresh()) {
            if (! filled($store->shopify_access_token)) {
                throw new ShopifyCredentialException(
                    'This store has no usable Shopify credentials.',
                    'credentials_missing',
                    permanent: true,
                );
            }

            return (string) $store->shopify_access_token;
        }

        $this->performRefresh($store, force: false);

        $store->refresh();

        return (string) $store->shopify_access_token;
    }

    private function performRefresh(Store $store, bool $force): void
    {
        if (! $store->hasShopifyCredentials()) {
            throw new ShopifyCredentialException(
                'This store has no usable Shopify credentials.',
                'credentials_missing',
                permanent: true,
            );
        }

        if (! $force && ! $store->accessTokenNeedsRefresh()) {
            return;
        }

        if ($store->refreshTokenIsExpired()) {
            Log::notice('Shopify refresh token is expired; marking credentials invalid.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $store->shopify_shop_domain,
            ]);

            $store->invalidateShopifyCredentials();

            throw new ShopifyCredentialException(
                'The Shopify refresh token has expired. Reconnect the store.',
                'refresh_token_expired',
                permanent: true,
            );
        }

        $shopDomain = (string) $store->shopify_shop_domain;
        $refreshToken = (string) $store->shopify_refresh_token;

        try {
            $payload = $this->client->refreshAccessToken($shopDomain, $refreshToken);
        } catch (ShopifyCredentialException $e) {
            if ($e->permanent) {
                $store->invalidateShopifyCredentials();
            }

            throw $e;
        }

        // Missing/empty scope on refresh is not revocation — keep prior grants.
        $rawScope = $payload['scope'] ?? null;
        $scopes = null;
        if (is_string($rawScope) && trim($rawScope) !== '') {
            $parsed = $this->parseScopes($rawScope);
            if ($parsed !== []) {
                $scopes = $parsed;
            }
        }

        try {
            $this->persistRotatedCredentials($store, $payload, $scopes);
        } catch (ShopifyCredentialException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Shopify may have rotated the refresh token server-side. The previous
            // refresh token remains usable until the new one is presented, so we
            // keep local credentials intact for a safe retry.
            Log::error('Failed to persist rotated Shopify credentials.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyCredentialException(
                'Rotated Shopify credentials could not be saved.',
                'token_refresh_persist_failed',
                permanent: false,
                previous: $e,
            );
        }

        Log::info('Shopify credentials refreshed.', [
            'store_id' => $store->getKey(),
            'shop_domain' => $shopDomain,
            'access_token_expires_in' => $payload['expires_in'],
            'refresh_token_expires_in' => $payload['refresh_token_expires_in'],
        ]);
    }

    /**
     * @param  array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int
     * }  $payload
     * @param  list<string>|null  $scopes  null = leave existing granted scopes unchanged
     */
    protected function persistRotatedCredentials(Store $store, array $payload, ?array $scopes): void
    {
        DB::transaction(function () use ($store, $payload, $scopes) {
            /** @var Store $locked */
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();

            $credentials = [
                'access_token' => $payload['access_token'],
                'refresh_token' => $payload['refresh_token'],
                'access_token_expires_at' => now()->addSeconds($payload['expires_in']),
                'refresh_token_expires_at' => now()->addSeconds($payload['refresh_token_expires_in']),
            ];

            if ($scopes !== null) {
                $credentials['scopes'] = $scopes;
            }

            $locked->rotateShopifyCredentials($credentials);
        });
    }

    private function lockKey(Store $store): string
    {
        return 'shopify-token-refresh:'.$store->getKey();
    }

    /**
     * @return list<string>
     */
    private function parseScopes(string $scope): array
    {
        if ($scope === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $scope))));
    }
}
