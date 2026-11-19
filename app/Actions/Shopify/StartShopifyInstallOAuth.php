<?php

namespace App\Actions\Shopify;

use App\Enums\StoreConnectionStatus;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Models\User;
use App\Support\Shopify\ShopifyScopes;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Domain-less Shopify install start for onboarding.
 * Redirects to Shopify's install picker; shop is bound on continue/callback.
 */
class StartShopifyInstallOAuth
{
    public function handle(User $user): string
    {
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Verify your email before connecting Shopify.'],
            ]);
        }

        $store = $this->resolvePendingStore($user);
        $workspace = $store->workspace;
        if ($workspace === null || ! $workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'store' => ['You are not a member of this store\'s workspace.'],
            ]);
        }

        $clientId = (string) config('services.shopify.client_id');
        $redirectUri = (string) config('services.shopify.redirect_uri');
        if ($clientId === '' || $redirectUri === '') {
            throw ValidationException::withMessages([
                'shopify' => ['Shopify OAuth is not configured.'],
            ]);
        }

        try {
            ShopifyScopes::authorizeScopeString();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'shopify' => ['Shopify OAuth scopes are misconfigured.'],
            ]);
        }

        $ttl = (int) config('services.shopify.oauth_state_ttl', 600);
        $state = Str::random(64);

        ShopifyOauthState::query()
            ->where('user_id', $user->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update(['consumed_at' => now()]);

        ShopifyOauthState::query()->create([
            'state' => $state,
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
            'store_id' => $store->getKey(),
            'shop_domain' => null,
            'expires_at' => now()->addSeconds($ttl),
        ]);

        // Merchant picks the shop in Shopify Admin; no myshopify domain typed in Inboxly.
        return 'https://admin.shopify.com/oauth/install?'.http_build_query([
            'client_id' => $clientId,
        ]);
    }

    private function resolvePendingStore(User $user): Store
    {
        $context = $user->workspaceContexts()->with('workspace')->orderByDesc('updated_at')->first();
        if ($context === null) {
            throw ValidationException::withMessages([
                'store' => ['Create an Inboxly account before connecting Shopify.'],
            ]);
        }

        if ($context->active_store_id) {
            $active = Store::query()->find($context->active_store_id);
            if ($active !== null && ! $active->trashed() && ! $active->is_demo) {
                return $active;
            }
        }

        $store = Store::query()
            ->where('workspace_id', $context->workspace_id)
            ->where('is_demo', false)
            ->whereIn('connection_status', [
                StoreConnectionStatus::Pending->value,
                StoreConnectionStatus::Disconnected->value,
                StoreConnectionStatus::Error->value,
                StoreConnectionStatus::Connected->value,
            ])
            ->orderByDesc('created_at')
            ->first();

        if ($store === null) {
            throw ValidationException::withMessages([
                'store' => ['No Inboxly store is available to connect to Shopify.'],
            ]);
        }

        return $store;
    }
}
