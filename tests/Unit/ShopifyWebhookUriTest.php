<?php

namespace Tests\Unit;

use App\Support\Shopify\ShopifyWebhookUri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyWebhookUriTest extends TestCase
{
    public function test_accepts_public_https_uri(): void
    {
        $uri = ShopifyWebhookUri::validate('https://hooks.example.com/api/v1/shopify/webhooks');
        $this->assertSame('https://hooks.example.com/api/v1/shopify/webhooks', $uri);
    }

    #[DataProvider('rejectedUriProvider')]
    public function test_rejects_unsafe_uris(string $uri): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShopifyWebhookUri::validate($uri, allowLocal: false);
    }

    public static function rejectedUriProvider(): array
    {
        return [
            'http' => ['http://hooks.example.com/api/v1/shopify/webhooks'],
            'localhost' => ['https://localhost/api/v1/shopify/webhooks'],
            'loopback' => ['https://127.0.0.1/api/v1/shopify/webhooks'],
            'private ip' => ['https://192.168.1.10/api/v1/shopify/webhooks'],
            'local tld' => ['https://app.test/api/v1/shopify/webhooks'],
            'file scheme' => ['file:///tmp/webhooks'],
            'userinfo' => ['https://user:pass@hooks.example.com/hooks'],
            'empty' => [''],
        ];
    }

    public function test_allow_local_permits_localhost_for_development(): void
    {
        $uri = ShopifyWebhookUri::validate(
            'https://localhost:8443/api/v1/shopify/webhooks',
            allowLocal: true,
        );

        $this->assertSame('https://localhost:8443/api/v1/shopify/webhooks', $uri);
    }
}
