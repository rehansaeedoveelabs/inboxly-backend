<?php

namespace App\Actions\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Models\User;
use App\Support\Shopify\ShopifyDomain;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StartShopifyOAuth
{
    /**
     * Create a single-use OAuth state row and return the Shopify authorize URL.
     *
     * Workspace is taken only from the Store — never from the client.
     * Authorize host is taken only from the persisted store domain — never from query input.
     *
     * A workspace context row is required (created on workspace join). active_store_id may
     * be null for a newly created pending store; that does not block OAuth and does not
     * weaken connectShopify / membership checks.
     */
    public function handle(User $user, Store $store): string
    {
        if ($store->is_demo || $store->connection_status === StoreConnectionStatus::Demo) {
            throw ValidationException::withMessages([
                'store' => ['Demo stores cannot be connected to Shopify.'],
            ]);
        }

        // Connected stores may reconnect; credentials stay intact until callback succeeds.
        if (! in_array($store->connection_status, [
            StoreConnectionStatus::Pending,
            StoreConnectionStatus::Disconnected,
            StoreConnectionStatus::Error,
            StoreConnectionStatus::Connected,
        ], true)) {
            throw ValidationException::withMessages([
                'store' => ['This store is not eligible for Shopify authorization.'],
            ]);
        }

        $workspace = $store->workspace;
        if ($workspace === null || ! $workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'store' => ['You are not a member of this store\'s workspace.'],
            ]);
        }

        // Active workspace context: user must have a context row for this workspace
        // (created on workspace join). Never trust client workspace IDs.
        $hasContext = $user->workspaceContexts()
            ->where('workspace_id', $workspace->getKey())
            ->exists();
        if (! $hasContext) {
            throw ValidationException::withMessages([
                'store' => ['Activate this workspace before connecting Shopify.'],
            ]);
        }

        try {
            $normalizedShop = ShopifyDomain::normalize((string) $store->domain);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'domain' => ['This store does not have a valid *.myshopify.com domain.'],
            ]);
        }

        $ownedElsewhere = Store::query()
            ->where('shopify_shop_domain', $normalizedShop)
            ->where('id', '!=', $store->getKey())
            ->exists();
        if ($ownedElsewhere) {
            throw ValidationException::withMessages([
                'domain' => ['This Shopify shop is already connected to another Inboxly store.'],
            ]);
        }

        $clientId = (string) config('services.shopify.client_id');
        $redirectUri = (string) config('services.shopify.redirect_uri');
        if ($clientId === '' || $redirectUri === '') {
            throw ValidationException::withMessages([
                'shopify' => ['Shopify OAuth is not configured.'],
            ]);
        }

        $state = Str::random(64);
        $ttl = (int) config('services.shopify.oauth_state_ttl', 600);

        ShopifyOauthState::query()->create([
            'state' => $state,
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'store_id' => $store->getKey(),
            'shop_domain' => $normalizedShop,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        $scopes = config('services.shopify.scopes', []);
        $scopeString = is_array($scopes) ? implode(',', $scopes) : '';

        return 'https://'.$normalizedShop.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'scope' => $scopeString,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }
}
