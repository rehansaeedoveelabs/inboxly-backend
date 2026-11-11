<?php

namespace App\Actions\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use App\Services\Shopify\ShopifyOAuthClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class DisconnectShopifyStore
{
    public function __construct(private readonly ShopifyOAuthClient $client) {}

    /**
     * Merchant-controlled disconnect. Always clears local credentials.
     * Attempts remote app uninstall/revoke when a usable access token exists.
     *
     * @return array{store: Store, remote_revoked: bool}
     */
    public function handle(Store $store): array
    {
        $store->refresh();

        if ($store->is_demo || $store->connection_status === StoreConnectionStatus::Demo) {
            return ['store' => $store, 'remote_revoked' => false];
        }

        $alreadyDisconnected = $store->shopify_access_token === null
            && $store->shopify_refresh_token === null
            && $store->connection_status === StoreConnectionStatus::Disconnected;

        if ($alreadyDisconnected) {
            return ['store' => $store, 'remote_revoked' => false];
        }

        $shopDomain = (string) ($store->shopify_shop_domain ?: $store->domain);
        $accessToken = (string) ($store->shopify_access_token ?? '');
        $remoteRevoked = false;

        if ($shopDomain !== '' && $accessToken !== '') {
            try {
                $remoteRevoked = $this->client->revokeAppAccess($shopDomain, $accessToken);
            } catch (Throwable $e) {
                // Local disconnect must still proceed.
                Log::notice('Shopify remote revoke failed during disconnect; clearing local credentials.', [
                    'store_id' => $store->getKey(),
                    'shop_domain' => $shopDomain,
                ]);
            }
        }

        DB::transaction(function () use ($store) {
            /** @var Store $locked */
            $locked = Store::query()->whereKey($store->getKey())->lockForUpdate()->firstOrFail();
            $locked->disconnectShopify();
        });

        Log::info('Shopify store disconnected.', [
            'store_id' => $store->getKey(),
            'shop_domain' => $shopDomain !== '' ? $shopDomain : null,
            'remote_revoked' => $remoteRevoked,
        ]);

        return [
            'store' => $store->fresh(),
            'remote_revoked' => $remoteRevoked,
        ];
    }
}
