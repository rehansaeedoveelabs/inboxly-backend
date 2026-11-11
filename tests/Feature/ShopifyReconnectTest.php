<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyReconnectTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, string>
     */
    private function signedCallbackQuery(array $overrides = []): array
    {
        $params = array_merge([
            'code' => 'shopify-auth-code',
            'shop' => 'reconnect-shop.myshopify.com',
            'state' => 'reconnect-state',
            'timestamp' => (string) now()->timestamp,
        ], $overrides);

        unset($params['hmac'], $params['signature']);
        ksort($params);
        $message = collect($params)->map(fn ($value, $key) => $key.'='.$value)->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));

        return $params;
    }

    private function fakeTokenAndIdentity(
        string $access = 'shpat_reconnected',
        string $refresh = 'shprt_reconnected',
    ): void {
        Http::fake([
            'https://reconnect-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => $access,
                'refresh_token' => $refresh,
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => '',
            ], 200),
            'https://reconnect-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'id' => 'gid://shopify/Shop/999',
                        'name' => 'Reconnect Shop',
                        'myshopifyDomain' => 'reconnect-shop.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'UTC',
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_reconnect_for_same_shop_rotates_credentials(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('reconnect-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);
        $store->forceFill([
            'shopify_access_token' => 'shpat_old',
            'shopify_refresh_token' => 'shprt_old',
            'shopify_shop_id' => 'gid://shopify/Shop/999',
        ])->save();

        $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertRedirect();

        $state = ShopifyOauthState::query()->where('store_id', $store->id)->latest('id')->first();
        $this->assertNotNull($state);
        $this->assertSame('shpat_old', $store->fresh()->shopify_access_token);

        $this->fakeTokenAndIdentity();

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->assertRedirect();

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertSame('shpat_reconnected', $fresh->shopify_access_token);
        $this->assertSame('shprt_reconnected', $fresh->shopify_refresh_token);
        $this->assertSame('reconnect-shop.myshopify.com', $fresh->shopify_shop_domain);
    }

    public function test_failed_reconnect_preserves_existing_valid_credentials(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('reconnect-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);
        $store->forceFill([
            'shopify_access_token' => 'shpat_keep',
            'shopify_refresh_token' => 'shprt_keep',
        ])->save();

        $state = ShopifyOauthState::query()->create([
            'state' => 'fail-reconnect',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'reconnect-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'https://reconnect-shop.myshopify.com/admin/oauth/access_token' => Http::response(['error' => 'invalid'], 400),
        ]);

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->headers->get('Location');

        $this->assertStringContainsString('reason=token_exchange_failed', $location);

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertSame('shpat_keep', $fresh->shopify_access_token);
        $this->assertSame('shprt_keep', $fresh->shopify_refresh_token);
    }

    public function test_invalid_callback_cannot_replace_credentials(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('reconnect-shop.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);
        $store->forceFill([
            'shopify_access_token' => 'shpat_keep',
            'shopify_refresh_token' => 'shprt_keep',
        ])->save();

        $state = ShopifyOauthState::query()->create([
            'state' => 'bad-hmac',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'reconnect-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        $query = $this->signedCallbackQuery(['state' => $state->state]);
        $query['hmac'] = str_repeat('a', 64);

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($query));

        $fresh = $store->fresh();
        $this->assertSame('shpat_keep', $fresh->shopify_access_token);
        $this->assertSame('shprt_keep', $fresh->shopify_refresh_token);
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertNull($state->fresh()->consumed_at);
    }

    public function test_cross_workspace_duplicate_ownership_is_rejected_on_reconnect_start(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        Store::factory()->withShopifyCredentials('shared-reconnect.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);

        [$adminB, $workspaceB] = $this->adminWithWorkspace('B');
        $storeB = Store::factory()->withShopifyCredentials('other.myshopify.com')
            ->create([
                'workspace_id' => $workspaceB->id,
                'domain' => 'shared-reconnect.myshopify.com',
            ]);
        // Domain points at a shop owned elsewhere; reconnect start must refuse.
        $storeB->forceFill([
            'domain' => 'shared-reconnect.myshopify.com',
            'shopify_shop_domain' => 'other.myshopify.com',
        ])->save();

        // Start uses persisted domain; ownership check uses normalized domain.
        $this->spa()->actingAs($adminB)
            ->getJson("/api/v1/stores/{$storeB->id}/shopify/oauth/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);
    }

    public function test_marketer_cannot_reconnect(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);
        $store = Store::factory()->withShopifyCredentials('marketer-reconnect.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        $this->spa()->actingAs($marketer)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertForbidden();
    }
}
