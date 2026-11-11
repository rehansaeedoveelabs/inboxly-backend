<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Models\Contact;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyDisconnectTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_admin_can_disconnect_and_credentials_are_cleared(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('disconnect-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);
        Contact::factory()->create(['store_id' => $store->id]);

        Http::fake([
            'https://disconnect-shop.myshopify.com/admin/api_permissions/current.json' => Http::response(null, 200),
        ]);

        $response = $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection");

        $response->assertOk()
            ->assertJsonPath('data.connection_status', 'disconnected')
            ->assertJsonPath('data.is_connected', false)
            ->assertJsonPath('meta.remote_revoked', true)
            ->assertJsonMissingPath('data.shopify_access_token')
            ->assertJsonMissingPath('data.shopify_refresh_token');

        $this->assertStringNotContainsString('shpat_', $response->getContent());

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Disconnected, $fresh->connection_status);
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertNull($fresh->shopify_access_token_expires_at);
        $this->assertNull($fresh->shopify_refresh_token_expires_at);
        $this->assertSame('disconnect-shop.myshopify.com', $fresh->shopify_shop_domain);
        $this->assertSame(1, $fresh->contacts()->count());

        Http::assertSent(function (Request $request) {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://disconnect-shop.myshopify.com/admin/api_permissions/current.json'
                && $request->hasHeader('X-Shopify-Access-Token');
        });
    }

    public function test_marketer_cannot_disconnect(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);
        $store = Store::factory()->withShopifyCredentials('marketer-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        Http::fake();

        $this->spa()->actingAs($marketer)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection")
            ->assertForbidden();

        $this->assertNotNull($store->fresh()->shopify_access_token);
        Http::assertNothingSent();
    }

    public function test_unauthenticated_cannot_disconnect(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('guest-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        $this->deleteJson("/api/v1/stores/{$store->id}/shopify/connection")
            ->assertUnauthorized();
    }

    public function test_cross_workspace_admin_cannot_disconnect(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        [$adminB] = $this->adminWithWorkspace('B');
        $store = Store::factory()->withShopifyCredentials('cross-shop.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);

        Http::fake();

        $this->spa()->actingAs($adminB)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection")
            ->assertForbidden();

        $this->assertNotNull($store->fresh()->shopify_access_token);
    }

    public function test_repeated_disconnect_is_idempotent(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('repeat-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        Http::fake([
            'https://repeat-shop.myshopify.com/admin/api_permissions/current.json' => Http::response(null, 200),
        ]);

        $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection")
            ->assertOk();

        Http::fake();

        $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection")
            ->assertOk()
            ->assertJsonPath('data.connection_status', 'disconnected')
            ->assertJsonPath('meta.remote_revoked', false);

        Http::assertNothingSent();
    }

    public function test_remote_revoke_failure_still_clears_local_connection(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('revoke-fail.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $response = $this->spa()->actingAs($admin)
            ->deleteJson("/api/v1/stores/{$store->id}/shopify/connection");

        $response->assertOk()
            ->assertJsonPath('data.connection_status', 'disconnected')
            ->assertJsonPath('data.is_connected', false)
            ->assertJsonPath('meta.remote_revoked', false);

        $fresh = $store->fresh();
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertSame('revoke-fail.myshopify.com', $fresh->shopify_shop_domain);
    }
}
