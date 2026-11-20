<?php

namespace Tests\Feature;

use App\Models\ShopifyProduct;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyProductListTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_lists_synced_products_with_featured_image_and_storefront_url(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('catalog-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'domain' => 'catalog.example.com',
            'shopify_scopes' => ['read_products'],
        ]);

        ShopifyProduct::factory()->create([
            'store_id' => $store->id,
            'title' => 'Alpha Tea',
            'handle' => 'alpha-tea',
            'status' => 'ACTIVE',
            'vendor' => 'Inboxly',
            'metadata' => [
                'featured_image_url' => 'https://cdn.example/alpha.jpg',
                'price_min' => '9.50',
                'price_max' => '9.50',
                'currency_code' => 'USD',
            ],
        ]);

        ShopifyProduct::factory()->create([
            'store_id' => $store->id,
            'title' => 'Beta Candle',
            'handle' => 'beta-candle',
            'status' => 'DRAFT',
            'metadata' => [],
        ]);

        $otherStore = Store::factory()->withShopifyCredentials('other-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);
        ShopifyProduct::factory()->create([
            'store_id' => $otherStore->id,
            'title' => 'Other Store Product',
            'handle' => 'other',
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/products")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Alpha Tea')
            ->assertJsonPath('data.0.featured_image_url', 'https://cdn.example/alpha.jpg')
            ->assertJsonPath('data.0.price_min', '9.50')
            ->assertJsonPath('data.0.currency_code', 'USD')
            ->assertJsonPath('data.0.product_url', 'https://catalog.example.com/products/alpha-tea')
            ->assertJsonPath('data.1.title', 'Beta Candle')
            ->assertJsonPath('data.1.featured_image_url', null);
    }

    public function test_filters_products_by_query_and_status(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('filter-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);

        ShopifyProduct::factory()->create([
            'store_id' => $store->id,
            'title' => 'Honey Jar',
            'handle' => 'honey-jar',
            'status' => 'ACTIVE',
            'vendor' => 'Bee Co',
        ]);
        ShopifyProduct::factory()->create([
            'store_id' => $store->id,
            'title' => 'Soap Bar',
            'handle' => 'soap-bar',
            'status' => 'DRAFT',
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/products?q=honey")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Honey Jar');

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/products?status=DRAFT")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Soap Bar');
    }

    public function test_cross_workspace_product_list_denied(): void
    {
        [$admin] = $this->adminWithWorkspace();

        [, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = Store::factory()->withShopifyCredentials('denied-shop.myshopify.com')->create([
            'workspace_id' => $otherWorkspace->id,
        ]);
        ShopifyProduct::factory()->create(['store_id' => $otherStore->id]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$otherStore->id}/shopify/products")
            ->assertForbidden();

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)
            ->getJson("/api/v1/stores/{$otherStore->id}/shopify/products")
            ->assertForbidden();
    }
}
