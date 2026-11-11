<?php

namespace Tests\Feature;

use App\Models\ShopifyOauthState;
use App\Models\Store;
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyOAuthRedirectTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function signedCallbackQuery(array $overrides = []): array
    {
        $params = array_merge([
            'code' => 'shopify-auth-code',
            'shop' => 'acme-shop.myshopify.com',
            'state' => 'test-state',
            'timestamp' => (string) now()->timestamp,
        ], $overrides);

        unset($params['hmac']);
        ksort($params);
        $message = collect($params)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));

        return $params;
    }

    public function test_local_configuration_redirects_error_to_local_frontend_url(): void
    {
        $this->assertSame('http://127.0.0.1:5173', config('app.frontend_url'));

        $query = $this->signedCallbackQuery();
        $query['hmac'] = str_repeat('ab', 32); // valid length/hex, wrong digest

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($query))
            ->headers->get('Location');

        $this->assertNotNull($location);
        $this->assertStringStartsWith('http://127.0.0.1:5173/settings/storefront?', $location);
        $this->assertStringContainsString('shopify=error', $location);
        $this->assertStringContainsString('reason=invalid_hmac', $location);
    }

    public function test_configured_https_frontend_url_is_used_for_error_and_success(): void
    {
        config(['app.frontend_url' => 'https://app.example.com']);

        $badQuery = $this->signedCallbackQuery();
        $badQuery['hmac'] = str_repeat('cd', 32);

        $errorLocation = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($badQuery))
            ->headers->get('Location');

        $this->assertStringStartsWith('https://app.example.com/settings/storefront?', $errorLocation);
        $this->assertStringContainsString('shopify=error', $errorLocation);
        $this->assertStringContainsString('reason=invalid_hmac', $errorLocation);

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        $state = ShopifyOauthState::query()->create([
            'state' => 'redirect-success-state',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'https://acme-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_access',
                'refresh_token' => 'shprt_test_refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => '',
            ], 200),
            'https://acme-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'id' => 'gid://shopify/Shop/1',
                        'name' => 'Acme',
                        'myshopifyDomain' => 'acme-shop.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'UTC',
                    ],
                ],
            ], 200),
        ]);

        $successLocation = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->headers->get('Location');

        $this->assertStringStartsWith('https://app.example.com/settings/storefront?', $successLocation);
        $this->assertStringContainsString('shopify=connected', $successLocation);
        $this->assertStringContainsString('store_id='.$store->id, $successLocation);
    }

    public function test_untrusted_host_and_referer_cannot_override_frontend_redirect(): void
    {
        config(['app.frontend_url' => 'https://trusted-frontend.example']);

        $query = $this->signedCallbackQuery();
        $query['hmac'] = str_repeat('ef', 32);

        $location = $this->withHeaders([
            'Host' => 'evil.example',
            'Referer' => 'https://evil.example/phish',
            'Origin' => 'https://evil.example',
            'X-Forwarded-Host' => 'evil.example',
            'X-Forwarded-Proto' => 'https',
        ])->get('/api/v1/shopify/oauth/callback?'.http_build_query($query))
            ->headers->get('Location');

        $this->assertStringStartsWith('https://trusted-frontend.example/settings/storefront?', $location);
        $this->assertStringNotContainsString('evil.example', (string) $location);
        $this->assertStringContainsString('shopify=error', $location);
        $this->assertStringContainsString('reason=invalid_hmac', $location);
    }

    public function test_frontend_url_helper_builds_from_config_only(): void
    {
        config(['app.frontend_url' => 'https://spa.example.com']);

        $this->assertSame('https://spa.example.com', FrontendUrl::base());
        $this->assertSame(
            'https://spa.example.com/settings/storefront?shopify=error&reason=invalid_hmac',
            FrontendUrl::to('/settings/storefront', [
                'shopify' => 'error',
                'reason' => 'invalid_hmac',
            ])
        );
    }
}
