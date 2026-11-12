<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyStoreCredentialsTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_shopify_tokens_are_encrypted_at_rest_and_readable_via_model(): void
    {
        [, $workspace] = $this->adminWithWorkspace();

        $store = Store::factory()->create(['workspace_id' => $workspace->id]);
        $store->storeShopifyCredentials([
            'shop_id' => '1234567890',
            'shop_domain' => 'Acme-Shop.myshopify.com',
            'access_token' => 'shpat_live_access_example',
            'refresh_token' => 'shprt_live_refresh_example',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => [],
        ]);

        $row = DB::table('stores')->where('id', $store->id)->first();

        $this->assertNotSame('shpat_live_access_example', $row->shopify_access_token);
        $this->assertNotSame('shprt_live_refresh_example', $row->shopify_refresh_token);
        $this->assertStringNotContainsString('shpat_live_access_example', (string) $row->shopify_access_token);
        $this->assertStringNotContainsString('shprt_live_refresh_example', (string) $row->shopify_refresh_token);

        $fresh = $store->fresh();
        $this->assertSame('shpat_live_access_example', $fresh->shopify_access_token);
        $this->assertSame('shprt_live_refresh_example', $fresh->shopify_refresh_token);
        $this->assertSame('acme-shop.myshopify.com', $fresh->shopify_shop_domain);
        $this->assertSame('1234567890', $fresh->shopify_shop_id);
        $this->assertSame([], $fresh->shopify_scopes);
        $this->assertTrue($fresh->hasShopifyCredentials());
        $this->assertTrue($fresh->isConnected());
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
    }

    public function test_shopify_tokens_are_hidden_from_arrays_and_api_resources(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        Store::factory()->withShopifyCredentials('hidden-tokens.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        $array = Store::query()->where('workspace_id', $workspace->id)->first()->toArray();
        $this->assertArrayNotHasKey('shopify_access_token', $array);
        $this->assertArrayNotHasKey('shopify_refresh_token', $array);

        $response = $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}/stores");
        $response->assertOk();
        $payload = $response->json('data.0');

        $this->assertSame('hidden-tokens.myshopify.com', $payload['shopify_shop_domain']);
        $this->assertSame([], $payload['shopify_scopes']);
        $this->assertTrue($payload['requires_shopify_reauth']);
        $this->assertEqualsCanonicalizing([
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ], $payload['missing_shopify_scopes']);
        $this->assertArrayNotHasKey('shopify_access_token', $payload);
        $this->assertArrayNotHasKey('shopify_refresh_token', $payload);
        $this->assertStringNotContainsString('shpat_test_access_token', $response->getContent());
        $this->assertStringNotContainsString('shprt_test_refresh_token', $response->getContent());
    }

    public function test_shopify_shop_domain_is_globally_unique_across_workspaces(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        [, $workspaceB] = $this->adminWithWorkspace('B');

        Store::factory()->withShopifyCredentials('shared-shop.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);

        $this->expectException(QueryException::class);

        Store::factory()->withShopifyCredentials('shared-shop.myshopify.com')
            ->create(['workspace_id' => $workspaceB->id]);
    }

    public function test_shopify_shop_id_is_globally_unique_across_workspaces(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        [, $workspaceB] = $this->adminWithWorkspace('B');

        $storeA = Store::factory()->create(['workspace_id' => $workspaceA->id]);
        $storeA->storeShopifyCredentials([
            'shop_id' => '999888777',
            'shop_domain' => 'shop-a.myshopify.com',
            'access_token' => 'shpat_a',
            'refresh_token' => 'shprt_a',
        ]);

        $storeB = Store::factory()->create(['workspace_id' => $workspaceB->id]);

        $this->expectException(QueryException::class);

        $storeB->storeShopifyCredentials([
            'shop_id' => '999888777',
            'shop_domain' => 'shop-b.myshopify.com',
            'access_token' => 'shpat_b',
            'refresh_token' => 'shprt_b',
        ]);
    }

    public function test_pending_stores_may_share_null_shopify_identity(): void
    {
        [, $workspace] = $this->adminWithWorkspace();

        Store::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'shopify_shop_id' => null,
            'shopify_shop_domain' => null,
        ]);

        $this->assertSame(2, Store::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_manual_domain_may_still_repeat_across_workspaces_without_shopify_identity(): void
    {
        // Phase 1 behaviour: Inboxly `domain` is workspace-scoped. Shopify identity is separate.
        [$adminA, $wsA] = $this->adminWithWorkspace('A');
        [$adminB, $wsB] = $this->adminWithWorkspace('B');

        $this->spa()->actingAs($adminA)->postJson("/api/v1/workspaces/{$wsA->id}/stores", [
            'name' => 'S', 'domain' => 'same.myshopify.com',
        ])->assertCreated()
            ->assertJsonPath('data.shopify_shop_domain', null);

        $this->spa()->actingAs($adminB)->postJson("/api/v1/workspaces/{$wsB->id}/stores", [
            'name' => 'S', 'domain' => 'same.myshopify.com',
        ])->assertCreated()
            ->assertJsonPath('data.shopify_shop_domain', null);
    }

    public function test_clients_cannot_mass_assign_shopify_tokens_through_store_create(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();

        $this->spa()->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/stores", [
            'name' => 'Inject',
            'domain' => 'inject.myshopify.com',
            'shopify_access_token' => 'shpat_injected',
            'shopify_refresh_token' => 'shprt_injected',
            'shopify_shop_domain' => 'inject.myshopify.com',
            'shopify_shop_id' => '111',
            'connection_status' => 'connected',
        ])->assertCreated()
            ->assertJsonPath('data.connection_status', 'pending')
            ->assertJsonPath('data.shopify_shop_domain', null)
            ->assertJsonPath('data.is_connected', false);

        $store = Store::query()->where('domain', 'inject.myshopify.com')->first();
        $this->assertNotNull($store);
        $this->assertNull($store->shopify_access_token);
        $this->assertNull($store->shopify_refresh_token);
        $this->assertNull($store->shopify_shop_domain);
        $this->assertNull($store->shopify_shop_id);
        $this->assertFalse($store->hasShopifyCredentials());
    }

    public function test_demo_store_cannot_retain_shopify_credentials(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id, 'is_demo' => true]);

        $store->forceFill([
            'shopify_shop_id' => '1',
            'shopify_shop_domain' => 'demo-should-clear.myshopify.com',
            'shopify_access_token' => 'shpat_demo',
            'shopify_refresh_token' => 'shprt_demo',
            'connection_status' => StoreConnectionStatus::Connected,
            'is_demo' => true,
        ])->save();

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Demo, $fresh->connection_status);
        $this->assertNull($fresh->shopify_shop_id);
        $this->assertNull($fresh->shopify_shop_domain);
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertFalse($fresh->isConnected());
    }

    public function test_soft_delete_releases_shopify_shop_domain_for_reconnect(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        [, $workspaceB] = $this->adminWithWorkspace('B');

        $store = Store::factory()->withShopifyCredentials('reconnect.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);
        $store->delete();

        $this->assertSoftDeleted($store);
        $this->assertDatabaseHas('stores', [
            'id' => $store->id,
            'shopify_shop_domain' => null,
            'shopify_shop_id' => null,
        ]);

        Store::factory()->withShopifyCredentials('reconnect.myshopify.com')
            ->create(['workspace_id' => $workspaceB->id]);

        $this->assertDatabaseHas('stores', [
            'workspace_id' => $workspaceB->id,
            'shopify_shop_domain' => 'reconnect.myshopify.com',
        ]);
    }

    public function test_cross_workspace_store_listing_does_not_leak_shopify_identity(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        Store::factory()->withShopifyCredentials('private-shop.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);

        [$adminB, $workspaceB] = $this->adminWithWorkspace('B');

        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceA->id}/stores")
            ->assertForbidden();

        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceB->id}/stores")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
