<?php

namespace Tests\Unit;

use App\Support\Shopify\ShopifyDomain;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShopifyDomainTest extends TestCase
{
    #[DataProvider('validDomains')]
    public function test_it_normalizes_valid_myshopify_hosts(string $input, string $expected): void
    {
        $this->assertSame($expected, ShopifyDomain::normalize($input));
        $this->assertTrue(ShopifyDomain::isValid($expected));
    }

    public static function validDomains(): array
    {
        return [
            'bare' => ['acme-shop.myshopify.com', 'acme-shop.myshopify.com'],
            'scheme and path' => ['https://Acme-Shop.myshopify.com/admin', 'acme-shop.myshopify.com'],
            'whitespace' => ['  cool.myshopify.com  ', 'cool.myshopify.com'],
        ];
    }

    #[DataProvider('invalidDomains')]
    public function test_it_rejects_malformed_and_external_hosts(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShopifyDomain::normalize($input);
    }

    public static function invalidDomains(): array
    {
        return [
            'external' => ['evil.example.com'],
            'port' => ['acme.myshopify.com:443'],
            'userinfo' => ['user:pass@acme.myshopify.com'],
            'query' => ['acme.myshopify.com?x=1'],
            'fragment' => ['acme.myshopify.com#top'],
            'suffix attack' => ['acme.myshopify.com.evil.com'],
            'empty' => ['   '],
            'custom domain' => ['store.brand.com'],
        ];
    }
}
