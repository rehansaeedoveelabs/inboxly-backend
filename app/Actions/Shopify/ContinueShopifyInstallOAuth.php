<?php

namespace App\Actions\Shopify;

use App\Models\ShopifyOauthState;
use App\Models\User;
use App\Support\Shopify\ShopifyDomain;
use App\Support\Shopify\ShopifyHmac;
use App\Support\Shopify\ShopifyScopes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * After Shopify install redirects with ?shop=, bind the shop to the open OAuth state
 * and return the classic authorize URL (includes state for the callback).
 */
class ContinueShopifyInstallOAuth
{
    /**
     * @param  array<string, mixed>  $query
     */
    public function handle(User $user, array $query): string
    {
        if (! $user->hasVerifiedEmail()) {
            throw ValidationException::withMessages([
                'email' => ['Verify your email before connecting Shopify.'],
            ]);
        }

        $appSecret = (string) config('services.shopify.app_secret');
        if ($appSecret !== '' && isset($query['hmac']) && is_string($query['hmac'])) {
            if (! ShopifyHmac::isValidCallback($query, $appSecret)) {
                throw ValidationException::withMessages([
                    'shop' => ['Invalid Shopify install signature.'],
                ]);
            }
        }

        try {
            $shopDomain = ShopifyDomain::normalize((string) ($query['shop'] ?? ''));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'shop' => ['A valid Shopify shop is required to continue installation.'],
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
            $scopeString = ShopifyScopes::authorizeScopeString();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'shopify' => ['Shopify OAuth scopes are misconfigured.'],
            ]);
        }

        $stateValue = DB::transaction(function () use ($user, $shopDomain) {
            /** @var ShopifyOauthState|null $oauthState */
            $oauthState = ShopifyOauthState::query()
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($oauthState === null) {
                throw ValidationException::withMessages([
                    'shopify' => ['Shopify install session expired. Start connect again.'],
                ]);
            }

            if ($oauthState->shop_domain !== null && $oauthState->shop_domain !== $shopDomain) {
                throw ValidationException::withMessages([
                    'shop' => ['Shopify shop does not match the open install session.'],
                ]);
            }

            $oauthState->forceFill(['shop_domain' => $shopDomain])->save();

            return $oauthState->state;
        });

        return 'https://'.$shopDomain.'/admin/oauth/authorize?'.http_build_query([
            'client_id' => $clientId,
            'scope' => $scopeString,
            'redirect_uri' => $redirectUri,
            'state' => $stateValue,
        ]);
    }
}
