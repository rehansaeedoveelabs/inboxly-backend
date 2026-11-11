<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyCredentialException;
use App\Exceptions\ShopifyOAuthException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShopifyOAuthClient
{
    /**
     * Exchange an authorization code for an expiring offline token pair.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int,
     *     scope: string
     * }
     */
    public function exchangeAuthorizationCode(string $shopDomain, string $code): array
    {
        $url = "https://{$shopDomain}/admin/oauth/access_token";

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(15)
                ->post($url, [
                    'client_id' => (string) config('services.shopify.client_id'),
                    'client_secret' => (string) config('services.shopify.app_secret'),
                    'code' => $code,
                    'expiring' => '1',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Shopify token exchange connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyOAuthException(
                'Unable to reach Shopify to complete authorization.',
                'token_exchange_failed',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            Log::warning('Shopify token exchange rejected.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyOAuthException(
                'Shopify rejected the authorization code exchange.',
                'token_exchange_failed',
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopifyOAuthException(
                'Shopify returned an invalid token response.',
                'token_exchange_invalid',
            );
        }

        return $this->assertTokenPayload($payload, 'token_exchange_invalid');
    }

    /**
     * Refresh an expiring offline access token using the stored refresh token.
     *
     * Official grant: POST /admin/oauth/access_token with grant_type=refresh_token.
     * Every successful refresh rotates both access and refresh tokens.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int,
     *     scope: string
     * }
     */
    public function refreshAccessToken(string $shopDomain, string $refreshToken): array
    {
        $url = "https://{$shopDomain}/admin/oauth/access_token";
        $timeout = max(1, (int) config('services.shopify.token_refresh_timeout_seconds', 15));

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($timeout)
                ->post($url, [
                    'client_id' => (string) config('services.shopify.client_id'),
                    'client_secret' => (string) config('services.shopify.app_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Shopify token refresh connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyCredentialException(
                'Unable to reach Shopify to refresh credentials.',
                'token_refresh_network',
                permanent: false,
                previous: $e,
            );
        }

        if ($response->status() === 401) {
            Log::notice('Shopify rejected the refresh token as permanently unusable.', [
                'shop_domain' => $shopDomain,
                'status' => 401,
            ]);

            throw new ShopifyCredentialException(
                'Shopify refresh credentials are no longer valid.',
                'token_refresh_invalid',
                permanent: true,
            );
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('Shopify token refresh temporarily failed.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyCredentialException(
                'Shopify temporarily rejected the credential refresh.',
                'token_refresh_transient',
                permanent: false,
            );
        }

        if (! $response->successful()) {
            Log::warning('Shopify token refresh was rejected.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            // Treat other 4xx responses as permanent so we do not spin forever.
            throw new ShopifyCredentialException(
                'Shopify rejected the credential refresh.',
                'token_refresh_rejected',
                permanent: $response->clientError(),
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopifyCredentialException(
                'Shopify returned an invalid refresh token response.',
                'token_refresh_malformed',
                permanent: false,
            );
        }

        try {
            return $this->assertTokenPayload($payload, 'token_refresh_malformed');
        } catch (ShopifyOAuthException $e) {
            throw new ShopifyCredentialException(
                $e->getMessage(),
                'token_refresh_malformed',
                permanent: false,
                previous: $e,
            );
        }
    }

    /**
     * Uninstall the app from the shop, which revokes offline access for this app.
     *
     * Official merchant/app uninstall endpoint (not a per-token OAuth revoke):
     * DELETE https://{shop}/admin/api_permissions/current.json
     *
     * @return bool True when Shopify confirmed the uninstall/revoke.
     */
    public function revokeAppAccess(string $shopDomain, string $accessToken): bool
    {
        $url = "https://{$shopDomain}/admin/api_permissions/current.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Accept' => 'application/json',
            ])->timeout(15)->delete($url);
        } catch (ConnectionException $e) {
            Log::warning('Shopify remote revoke connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyCredentialException(
                'Unable to reach Shopify to revoke app access.',
                'remote_revoke_network',
                permanent: false,
                previous: $e,
            );
        }

        // 200/204 success; 401/404 mean access is already gone — treat as revoked.
        if ($response->successful() || in_array($response->status(), [401, 404], true)) {
            return true;
        }

        Log::warning('Shopify remote revoke returned an unexpected status.', [
            'shop_domain' => $shopDomain,
            'status' => $response->status(),
        ]);

        throw new ShopifyCredentialException(
            'Shopify did not confirm app access revocation.',
            'remote_revoke_failed',
            permanent: false,
        );
    }

    /**
     * Verify shop identity with the Admin GraphQL API (no customer/order/product fields).
     *
     * @return array{id: string, myshopify_domain: string, name: string, currency_code: ?string, timezone: ?string}
     */
    public function fetchShopIdentity(string $shopDomain, string $accessToken): array
    {
        $version = (string) config('services.shopify.api_version', '2026-10');
        $url = "https://{$shopDomain}/admin/api/{$version}/graphql.json";

        $query = <<<'GRAPHQL'
        query ShopIdentity {
          shop {
            id
            name
            myshopifyDomain
            currencyCode
            ianaTimezone
          }
        }
        GRAPHQL;

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($url, ['query' => $query]);
        } catch (ConnectionException $e) {
            Log::warning('Shopify GraphQL identity connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyOAuthException(
                'Unable to reach Shopify to verify the shop.',
                'identity_verification_failed',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            Log::warning('Shopify GraphQL identity request failed.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyOAuthException(
                'Shopify shop identity verification failed.',
                'identity_verification_failed',
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopifyOAuthException(
                'Shopify returned an invalid identity response.',
                'identity_verification_failed',
            );
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            if ($this->errorsIndicateMissingScope($errors)) {
                throw new ShopifyOAuthException(
                    'Shopify identity verification requires a permission that is not in the configured empty scope list. Do not add customer, order, or product scopes silently.',
                    'identity_permission_required',
                );
            }

            Log::warning('Shopify GraphQL identity returned errors.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyOAuthException(
                'Shopify shop identity verification failed.',
                'identity_verification_failed',
            );
        }

        $shop = data_get($payload, 'data.shop');
        if (! is_array($shop) || ! filled($shop['id'] ?? null) || ! filled($shop['myshopifyDomain'] ?? null)) {
            throw new ShopifyOAuthException(
                'Shopify shop identity response was incomplete.',
                'identity_verification_failed',
            );
        }

        return [
            'id' => (string) $shop['id'],
            'myshopify_domain' => mb_strtolower((string) $shop['myshopifyDomain']),
            'name' => (string) ($shop['name'] ?? ''),
            'currency_code' => isset($shop['currencyCode']) ? (string) $shop['currencyCode'] : null,
            'timezone' => isset($shop['ianaTimezone']) ? (string) $shop['ianaTimezone'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     expires_in: int,
     *     refresh_token_expires_in: int,
     *     scope: string
     * }
     */
    private function assertTokenPayload(array $payload, string $reasonCode = 'token_exchange_invalid'): array
    {
        foreach (['access_token', 'refresh_token', 'expires_in', 'refresh_token_expires_in'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                throw new ShopifyOAuthException(
                    'Shopify token response was missing required expiring-offline fields.',
                    $reasonCode,
                );
            }
        }

        if (! is_string($payload['access_token']) || ! is_string($payload['refresh_token'])) {
            throw new ShopifyOAuthException(
                'Shopify token response contained invalid token types.',
                $reasonCode,
            );
        }

        return [
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'],
            'expires_in' => $this->assertPositiveExpirySeconds($payload['expires_in'], $reasonCode),
            'refresh_token_expires_in' => $this->assertPositiveExpirySeconds($payload['refresh_token_expires_in'], $reasonCode),
            'scope' => is_string($payload['scope'] ?? null) ? $payload['scope'] : '',
        ];
    }

    /**
     * Require a positive whole-number lifetime in seconds.
     * Never silently cast malformed values to 0.
     */
    private function assertPositiveExpirySeconds(mixed $value, string $reasonCode = 'token_exchange_invalid'): int
    {
        if (is_int($value)) {
            if ($value <= 0) {
                throw new ShopifyOAuthException(
                    'Shopify token response contained invalid expiry values.',
                    $reasonCode,
                );
            }

            return $value;
        }

        // Digit strings only (e.g. JSON-decoded edge cases). Reject decimals, signs, junk.
        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        throw new ShopifyOAuthException(
            'Shopify token response contained invalid expiry values.',
            $reasonCode,
        );
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function errorsIndicateMissingScope(array $errors): bool
    {
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }
            $code = (string) data_get($error, 'extensions.code', '');
            $message = mb_strtolower((string) ($error['message'] ?? ''));
            if ($code === 'ACCESS_DENIED' || (str_contains($message, 'access') && str_contains($message, 'scope'))) {
                return true;
            }
        }

        return false;
    }
}
