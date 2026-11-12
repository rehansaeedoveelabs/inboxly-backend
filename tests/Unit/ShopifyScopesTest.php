<?php

namespace Tests\Unit;

use App\Support\Shopify\ShopifyScopes;
use InvalidArgumentException;
use Tests\TestCase;

class ShopifyScopesTest extends TestCase
{
    public function test_unset_env_uses_required_fallback(): void
    {
        $required = [
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ];

        $this->assertSame(
            $required,
            ShopifyScopes::requestedFromEnv(null, $required),
        );
    }

    public function test_explicit_empty_and_whitespace_env_resolve_to_empty_list(): void
    {
        $required = [
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ];

        $this->assertSame([], ShopifyScopes::requestedFromEnv('', $required));
        $this->assertSame([], ShopifyScopes::requestedFromEnv('   ', $required));
        $this->assertSame([], ShopifyScopes::requestedFromEnv(', ,', $required));
    }

    public function test_custom_env_is_normalized(): void
    {
        $required = [
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ];

        $this->assertSame(
            ['read_customers', 'read_products', 'read_orders', 'read_all_orders', 'read_locations'],
            ShopifyScopes::requestedFromEnv(
                ' read_customers, read_products,read_orders,read_all_orders, read_locations ',
                $required,
            ),
        );
    }

    public function test_assert_usable_rejects_empty_and_incomplete_requested_scopes(): void
    {
        config([
            'services.shopify.required_scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
            ],
            'services.shopify.scopes' => [],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shopify OAuth scopes are not configured.');
        ShopifyScopes::assertUsableForAuthorize();
    }

    public function test_assert_usable_rejects_requested_scopes_missing_required(): void
    {
        config([
            'services.shopify.required_scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
            ],
            'services.shopify.scopes' => ['read_products', 'read_orders'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Shopify OAuth scopes omit required permissions.');
        ShopifyScopes::assertUsableForAuthorize();
    }

    public function test_assert_usable_accepts_requested_superset_of_required(): void
    {
        config([
            'services.shopify.required_scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
            ],
            'services.shopify.scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
                'read_locations',
            ],
        ]);

        $this->assertSame(
            'read_customers,read_products,read_orders,read_all_orders,read_locations',
            ShopifyScopes::authorizeScopeString(),
        );
    }
}
