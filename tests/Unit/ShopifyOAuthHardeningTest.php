<?php

namespace Tests\Unit;

use App\Exceptions\ShopifyOAuthException;
use App\Services\Shopify\ShopifyOAuthClient;
use App\Support\Shopify\ShopifyHmac;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyOAuthHardeningTest extends TestCase
{
    private function callbackParams(array $overrides = []): array
    {
        return array_merge([
            'code' => 'shopify-auth-code',
            'shop' => 'acme-shop.myshopify.com',
            'state' => 'test-state',
            'timestamp' => '1730000000',
        ], $overrides);
    }

    private function validHmacFor(array $params): string
    {
        unset($params['hmac'], $params['signature']);
        ksort($params);
        $message = collect($params)
            ->map(fn ($value, $key) => $key.'='.$value)
            ->implode('&');

        return hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));
    }

    public function test_hmac_rejects_incorrect_length_without_throwing(): void
    {
        $params = $this->callbackParams();
        $params['hmac'] = 'deadbeef'; // too short

        $this->assertFalse(ShopifyHmac::isValidCallback(
            $params,
            (string) config('services.shopify.app_secret')
        ));
    }

    public function test_hmac_rejects_non_hexadecimal_characters_without_throwing(): void
    {
        $params = $this->callbackParams();
        $params['hmac'] = str_repeat('g', 64); // length OK, not hex

        $this->assertFalse(ShopifyHmac::isValidCallback(
            $params,
            (string) config('services.shopify.app_secret')
        ));
    }

    public function test_valid_hmac_still_succeeds(): void
    {
        $params = $this->callbackParams();
        $params['hmac'] = $this->validHmacFor($params);

        $this->assertTrue(ShopifyHmac::isValidCallback(
            $params,
            (string) config('services.shopify.app_secret')
        ));
    }

    #[DataProvider('invalidExpiryProvider')]
    public function test_token_exchange_rejects_invalid_expiry_values(array $expiryOverrides): void
    {
        Http::fake([
            'https://acme-shop.myshopify.com/admin/oauth/access_token' => Http::response(array_merge([
                'access_token' => 'shpat_test_access',
                'refresh_token' => 'shprt_test_refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => '',
            ], $expiryOverrides), 200),
        ]);

        try {
            app(ShopifyOAuthClient::class)->exchangeAuthorizationCode(
                'acme-shop.myshopify.com',
                'shopify-auth-code'
            );
            $this->fail('Expected ShopifyOAuthException was not thrown.');
        } catch (ShopifyOAuthException $e) {
            $this->assertSame('token_exchange_invalid', $e->reasonCode);
        }
    }

    public static function invalidExpiryProvider(): array
    {
        return [
            'missing expires_in' => [['expires_in' => null]],
            'missing refresh_token_expires_in' => [['refresh_token_expires_in' => null]],
            'non-numeric expires_in' => [['expires_in' => 'soon']],
            'non-numeric refresh expiry' => [['refresh_token_expires_in' => 'later']],
            'zero expires_in' => [['expires_in' => 0]],
            'zero refresh expiry' => [['refresh_token_expires_in' => 0]],
            'negative expires_in' => [['expires_in' => -1]],
            'negative refresh expiry' => [['refresh_token_expires_in' => -100]],
            'decimal expires_in string' => [['expires_in' => '3600.5']],
        ];
    }

    public function test_valid_token_response_still_succeeds(): void
    {
        Http::fake([
            'https://acme-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test_access',
                'refresh_token' => 'shprt_test_refresh',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => '',
            ], 200),
        ]);

        $tokens = app(ShopifyOAuthClient::class)->exchangeAuthorizationCode(
            'acme-shop.myshopify.com',
            'shopify-auth-code'
        );

        $this->assertSame('shpat_test_access', $tokens['access_token']);
        $this->assertSame('shprt_test_refresh', $tokens['refresh_token']);
        $this->assertSame(3600, $tokens['expires_in']);
        $this->assertSame(7776000, $tokens['refresh_token_expires_in']);
    }
}
