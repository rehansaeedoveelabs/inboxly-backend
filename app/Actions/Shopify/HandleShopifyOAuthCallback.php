<?php

namespace App\Actions\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Exceptions\ShopifyOAuthException;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Services\Shopify\ShopifyOAuthClient;
use App\Support\Shopify\ShopifyDomain;
use App\Support\Shopify\ShopifyHmac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class HandleShopifyOAuthCallback
{
    public function __construct(private readonly ShopifyOAuthClient $client) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array{store: Store, shop_domain: string}
     */
    public function handle(array $query): array
    {
        if (filled($query['error'] ?? null)) {
            throw new ShopifyOAuthException(
                'Shopify authorization was cancelled or denied.',
                'authorization_denied',
            );
        }

        $appSecret = (string) config('services.shopify.app_secret');
        if (! ShopifyHmac::isValidCallback($query, $appSecret)) {
            throw new ShopifyOAuthException('Invalid Shopify callback signature.', 'invalid_hmac');
        }

        $this->assertFreshTimestamp($query['timestamp'] ?? null);

        try {
            $shopDomain = ShopifyDomain::normalize((string) ($query['shop'] ?? ''));
        } catch (InvalidArgumentException) {
            throw new ShopifyOAuthException('Invalid shop domain in Shopify callback.', 'invalid_shop');
        }

        $code = $query['code'] ?? null;
        $stateValue = $query['state'] ?? null;
        if (! is_string($code) || $code === '' || ! is_string($stateValue) || $stateValue === '') {
            throw new ShopifyOAuthException('Shopify callback was missing required parameters.', 'malformed_callback');
        }

        $oauthState = $this->consumeState($stateValue, $shopDomain);
        $store = Store::query()->with('workspace')->find($oauthState->store_id);

        if ($store === null || $store->trashed()) {
            throw new ShopifyOAuthException('The Inboxly store for this authorization no longer exists.', 'store_missing');
        }

        if ($store->workspace_id !== $oauthState->workspace_id) {
            throw new ShopifyOAuthException('Workspace mismatch for Shopify authorization.', 'workspace_mismatch');
        }

        if ($store->is_demo) {
            throw new ShopifyOAuthException('Demo stores cannot be connected to Shopify.', 'demo_store');
        }

        $isReconnect = $store->hasShopifyCredentials()
            || $store->connection_status === StoreConnectionStatus::Connected;

        // Reconnect must target the same persisted shop host — never a client override.
        if ($isReconnect) {
            $expectedDomain = mb_strtolower((string) ($store->shopify_shop_domain ?: $store->domain));
            if ($expectedDomain !== '' && $expectedDomain !== $shopDomain) {
                throw new ShopifyOAuthException(
                    'Shopify shop domain does not match this store.',
                    'reconnect_shop_mismatch',
                );
            }
        }

        $ownedElsewhere = Store::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('id', '!=', $store->getKey())
            ->exists();
        if ($ownedElsewhere) {
            if (! $isReconnect) {
                $this->markStoreError($store);
            }
            throw new ShopifyOAuthException(
                'This Shopify shop is already connected to another Inboxly store.',
                'shop_already_owned',
            );
        }

        try {
            $tokens = $this->client->exchangeAuthorizationCode($shopDomain, $code);
            $identity = $this->client->fetchShopIdentity($shopDomain, $tokens['access_token']);
        } catch (ShopifyOAuthException $e) {
            if (! $isReconnect) {
                $this->markStoreError($store);
            }
            throw $e;
        } catch (Throwable $e) {
            if (! $isReconnect) {
                $this->markStoreError($store);
            }
            Log::warning('Unexpected Shopify OAuth failure.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $shopDomain,
            ]);
            throw new ShopifyOAuthException(
                'Shopify authorization could not be completed.',
                'authorization_failed',
                previous: $e,
            );
        }

        if ($identity['myshopify_domain'] !== $shopDomain) {
            if (! $isReconnect) {
                $this->markStoreError($store);
            }
            throw new ShopifyOAuthException(
                'Shopify shop domain did not match the authorized shop.',
                'identity_mismatch',
            );
        }

        $scopes = array_values(array_filter(array_map(
            'trim',
            explode(',', $tokens['scope'])
        )));

        try {
            DB::transaction(function () use ($store, $tokens, $identity, $shopDomain, $scopes) {
                $store->forceFill([
                    'domain' => $shopDomain,
                    'name' => $identity['name'] !== '' ? $identity['name'] : $store->name,
                    'currency' => $identity['currency_code'] ? strtoupper($identity['currency_code']) : $store->currency,
                    'timezone' => $identity['timezone'] ?: $store->timezone,
                ])->save();

                $store->storeShopifyCredentials([
                    'shop_id' => $identity['id'],
                    'shop_domain' => $shopDomain,
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'access_token_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'refresh_token_expires_at' => now()->addSeconds($tokens['refresh_token_expires_in']),
                    'scopes' => $scopes,
                ]);
            });
        } catch (Throwable $e) {
            if (! $isReconnect) {
                $this->markStoreError($store);
            }
            Log::warning('Failed to persist Shopify credentials.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $shopDomain,
            ]);
            throw new ShopifyOAuthException(
                'Shopify authorization succeeded but credentials could not be saved.',
                'persist_failed',
                previous: $e,
            );
        }

        // Best-effort: never roll back a successful connect if registration fails.
        try {
            RegisterShopifyWebhooksJob::dispatch($store->getKey());
        } catch (Throwable $e) {
            Log::warning('Failed to queue Shopify webhook registration.', [
                'store_id' => $store->getKey(),
                'shop_domain' => $shopDomain,
            ]);
        }

        return [
            'store' => $store->fresh(),
            'shop_domain' => $shopDomain,
        ];
    }

    private function assertFreshTimestamp(mixed $timestamp): void
    {
        if (! is_numeric($timestamp)) {
            throw new ShopifyOAuthException('Shopify callback timestamp was missing.', 'invalid_timestamp');
        }

        $ts = (int) $timestamp;
        // Reject callbacks older than 10 minutes (Shopify clock skew buffer).
        if (abs(now()->timestamp - $ts) > 600) {
            throw new ShopifyOAuthException('Shopify callback timestamp is stale.', 'stale_timestamp');
        }
    }

    private function consumeState(string $stateValue, string $shopDomain): ShopifyOauthState
    {
        return DB::transaction(function () use ($stateValue, $shopDomain) {
            /** @var ShopifyOauthState|null $oauthState */
            $oauthState = ShopifyOauthState::query()
                ->where('state', $stateValue)
                ->lockForUpdate()
                ->first();

            if ($oauthState === null) {
                throw new ShopifyOAuthException('Unknown OAuth state.', 'invalid_state');
            }

            if ($oauthState->isConsumed()) {
                throw new ShopifyOAuthException('OAuth state was already used.', 'state_replay');
            }

            if ($oauthState->isExpired()) {
                throw new ShopifyOAuthException('OAuth state has expired.', 'state_expired');
            }

            if ($oauthState->shop_domain !== $shopDomain) {
                throw new ShopifyOAuthException('OAuth state shop mismatch.', 'state_shop_mismatch');
            }

            $oauthState->forceFill(['consumed_at' => now()])->save();

            return $oauthState;
        });
    }

    private function markStoreError(Store $store): void
    {
        if ($store->is_demo) {
            return;
        }

        $store->forceFill([
            'connection_status' => StoreConnectionStatus::Error,
        ])->save();
    }
}
