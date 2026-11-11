<?php

namespace App\Http\Controllers\Api\V1\Shopify;

use App\Actions\Shopify\HandleShopifyOAuthCallback;
use App\Actions\Shopify\StartShopifyOAuth;
use App\Exceptions\ShopifyOAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shopify\StartShopifyOAuthRequest;
use App\Models\Store;
use App\Support\FrontendUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShopifyOAuthController extends Controller
{
    /**
     * Authenticated SPA entry: validates the store, persists OAuth state, redirects to Shopify.
     */
    public function start(
        StartShopifyOAuthRequest $request,
        Store $store,
        StartShopifyOAuth $startShopifyOAuth,
    ): RedirectResponse {
        $url = $startShopifyOAuth->handle($request->user(), $store);

        return redirect()->away($url);
    }

    /**
     * Public Shopify redirect target — no browser session required.
     * Full path: /api/v1/shopify/oauth/callback
     */
    public function callback(Request $request, HandleShopifyOAuthCallback $handler): RedirectResponse
    {
        try {
            $result = $handler->handle($request->query());

            return $this->redirectToFrontend('connected', [
                'store_id' => $result['store']->getKey(),
            ]);
        } catch (ValidationException $e) {
            return $this->redirectToFrontend('error', [
                'reason' => 'validation',
            ]);
        } catch (ShopifyOAuthException $e) {
            Log::notice('Shopify OAuth callback rejected.', [
                'reason' => $e->reasonCode,
            ]);

            return $this->redirectToFrontend('error', [
                'reason' => $e->reasonCode,
            ]);
        }
    }

    /**
     * Always redirect to the configured FRONTEND_URL origin (never Host/Referer).
     *
     * @param  array<string, string>  $query
     */
    private function redirectToFrontend(string $status, array $query = []): RedirectResponse
    {
        $params = array_merge(['shopify' => $status], $query);

        return redirect()->away(FrontendUrl::to('/settings/storefront', $params));
    }
}
