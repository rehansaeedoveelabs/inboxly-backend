<?php

namespace Tests\Feature;

use Tests\TestCase;

class ShopifyConfigTest extends TestCase
{
    public function test_shopify_credentials_are_mapped_through_services_config(): void
    {
        $this->assertSame('test-shopify-client-id', config('services.shopify.client_id'));
        $this->assertSame('test-shopify-app-secret', config('services.shopify.app_secret'));
        $this->assertSame(
            'https://example.test/api/v1/shopify/oauth/callback',
            config('services.shopify.redirect_uri')
        );
    }

    public function test_shopify_scopes_default_to_commerce_baseline(): void
    {
        $required = config('services.shopify.required_scopes');
        $scopes = config('services.shopify.scopes');

        $this->assertIsArray($required);
        $this->assertIsArray($scopes);
        $this->assertSame([
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ], $required);
        $this->assertSame($required, $scopes);
        $this->assertEmpty(array_intersect($scopes, [
            'write_customers',
            'write_orders',
            'write_products',
        ]));
    }

    public function test_shopify_config_has_no_legacy_api_key_aliases(): void
    {
        $shopify = config('services.shopify');

        $this->assertIsArray($shopify);
        $this->assertArrayHasKey('client_id', $shopify);
        $this->assertArrayHasKey('app_secret', $shopify);
        $this->assertArrayHasKey('scopes', $shopify);
        $this->assertArrayHasKey('redirect_uri', $shopify);
        $this->assertArrayNotHasKey('api_key', $shopify);
        $this->assertArrayNotHasKey('api_secret', $shopify);
    }
}
