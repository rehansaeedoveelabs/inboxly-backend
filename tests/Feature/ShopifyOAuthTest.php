<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Models\ShopifyOauthState;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyOAuthTest extends TestCase
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

    private function fakeSuccessfulShopifyHttp(): void
    {
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
                        'id' => 'gid://shopify/Shop/1234567890',
                        'name' => 'Acme Shop',
                        'myshopifyDomain' => 'acme-shop.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'America/New_York',
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_admin_can_start_oauth_and_is_redirected_to_shopify(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $response = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('https://acme-shop.myshopify.com/admin/oauth/authorize?', $location);
        $this->assertStringContainsString('client_id=test-shopify-client-id', $location);
        $this->assertStringContainsString(
            'redirect_uri='.urlencode('https://example.test/api/v1/shopify/oauth/callback'),
            $location
        );

        $this->assertDatabaseCount('shopify_oauth_states', 1);
        $state = ShopifyOauthState::query()->first();
        $this->assertSame($admin->id, $state->user_id);
        $this->assertSame($workspace->id, $state->workspace_id);
        $this->assertSame($store->id, $state->store_id);
        $this->assertSame('acme-shop.myshopify.com', $state->shop_domain);
        $this->assertNull($state->consumed_at);
        $this->assertTrue($state->expires_at->isFuture());
    }

    public function test_oauth_start_rejects_invalid_persisted_domain(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'example.com',
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);

        $this->assertDatabaseCount('shopify_oauth_states', 0);
        $this->assertSame('pending', $store->fresh()->connection_status->value);
    }

    public function test_oauth_start_ignores_client_shop_override_and_uses_persisted_domain(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $response = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start?shop=attacker-shop.myshopify.com");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertStringStartsWith('https://acme-shop.myshopify.com/admin/oauth/authorize?', $location);
        $this->assertStringNotContainsString('attacker-shop.myshopify.com', $location);

        $state = ShopifyOauthState::query()->first();
        $this->assertNotNull($state);
        $this->assertSame('acme-shop.myshopify.com', $state->shop_domain);
        $this->assertSame($store->id, $state->store_id);
        $this->assertSame($admin->id, $state->user_id);
        $this->assertSame($workspace->id, $state->workspace_id);
    }

    public function test_pending_store_can_start_oauth_without_active_store_id(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'pending-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $this->assertDatabaseHas('user_workspace_contexts', [
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'active_store_id' => null,
        ]);

        $response = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start");

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://pending-shop.myshopify.com/admin/oauth/authorize?',
            $response->headers->get('Location')
        );
        $this->assertDatabaseCount('shopify_oauth_states', 1);
        $this->assertSame('pending', $store->fresh()->connection_status->value);
    }

    public function test_oauth_start_rejects_demo_but_allows_reconnect_for_connected_stores(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $demo = Store::factory()->demo()->create(['workspace_id' => $workspace->id]);
        $connected = Store::factory()->withShopifyCredentials('already.myshopify.com')
            ->create(['workspace_id' => $workspace->id]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$demo->id}/shopify/oauth/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['store']);

        $response = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$connected->id}/shopify/oauth/start");

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://already.myshopify.com/admin/oauth/authorize?',
            $response->headers->get('Location')
        );
        // Existing credentials must remain until callback succeeds.
        $this->assertNotNull($connected->fresh()->shopify_access_token);
        $this->assertSame(StoreConnectionStatus::Connected, $connected->fresh()->connection_status);
    }

    public function test_oauth_start_is_forbidden_across_workspaces_and_for_marketers(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        $store = Store::factory()->create([
            'workspace_id' => $workspaceA->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        [$adminB] = $this->adminWithWorkspace('B');
        $marketer = $this->marketerIn($workspaceA);

        $this->spa()->actingAs($adminB)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertForbidden();

        $this->spa()->actingAs($marketer)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertForbidden();
    }

    public function test_successful_oauth_callback_connects_store_after_identity_verification(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);

        $state = ShopifyOauthState::query()->create([
            'state' => 'valid-state-token',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->fakeSuccessfulShopifyHttp();

        $response = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ));

        $response->assertRedirect();
        $this->assertStringContainsString('shopify=connected', $response->headers->get('Location'));
        $this->assertStringContainsString('store_id='.$store->id, $response->headers->get('Location'));

        $fresh = $store->fresh();
        $this->assertTrue($fresh->isConnected());
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertSame('acme-shop.myshopify.com', $fresh->shopify_shop_domain);
        $this->assertSame('gid://shopify/Shop/1234567890', $fresh->shopify_shop_id);
        $this->assertSame('shpat_test_access', $fresh->shopify_access_token);
        $this->assertSame('shprt_test_refresh', $fresh->shopify_refresh_token);
        $this->assertSame([], $fresh->shopify_scopes);
        $this->assertNotNull($fresh->shopify_access_token_expires_at);
        $this->assertNotNull($fresh->shopify_refresh_token_expires_at);

        $row = DB::table('stores')->where('id', $store->id)->first();
        $this->assertNotSame('shpat_test_access', $row->shopify_access_token);
        $this->assertNotSame('shprt_test_refresh', $row->shopify_refresh_token);

        $this->assertNotNull($state->fresh()->consumed_at);

        $list = $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}/stores");
        $list->assertOk();
        $this->assertArrayNotHasKey('shopify_access_token', $list->json('data.0'));
        $this->assertArrayNotHasKey('shopify_refresh_token', $list->json('data.0'));
        $this->assertStringNotContainsString('shpat_test_access', $list->getContent());
    }

    public function test_callback_rejects_invalid_hmac_and_leaves_store_pending(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        ShopifyOauthState::query()->create([
            'state' => 'hmac-state',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        $query = $this->signedCallbackQuery(['state' => 'hmac-state']);
        $query['hmac'] = 'deadbeef';

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($query))
            ->assertRedirect();

        $this->assertSame('pending', $store->fresh()->connection_status->value);
        $this->assertNull($store->fresh()->shopify_access_token);
        $this->assertNull(ShopifyOauthState::query()->where('state', 'hmac-state')->value('consumed_at'));
    }

    public function test_callback_rejects_missing_expired_and_replayed_state(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);

        $expired = ShopifyOauthState::query()->create([
            'state' => 'expired-state',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->subMinute(),
        ]);

        $missingLocation = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => 'missing-state'])
        ))->headers->get('Location');
        $this->assertStringContainsString('reason=invalid_state', $missingLocation);

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $expired->state])
        ));
        $this->assertStringContainsString(
            'reason=state_expired',
            $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
                $this->signedCallbackQuery(['state' => $expired->state])
            ))->headers->get('Location')
        );
        $this->assertSame('pending', $store->fresh()->connection_status->value);

        $replay = ShopifyOauthState::query()->create([
            'state' => 'replay-state',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => now(),
        ]);

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $replay->state])
        ))->headers->get('Location');
        $this->assertStringContainsString('reason=state_replay', $location);
        $this->assertSame('pending', $store->fresh()->connection_status->value);
    }

    public function test_callback_rejects_invalid_shop_domain(): void
    {
        $query = $this->signedCallbackQuery(['shop' => 'not-shopify.example.com']);
        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($query))
            ->headers->get('Location');

        $this->assertStringContainsString('reason=invalid_shop', $location);
    }

    public function test_token_exchange_failure_marks_store_error_without_tokens(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        $state = ShopifyOauthState::query()->create([
            'state' => 'token-fail-state',
            'user_id' => $admin->id,
            'workspace_id' => $workspace->id,
            'store_id' => $store->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'https://acme-shop.myshopify.com/admin/oauth/access_token' => Http::response(['error' => 'invalid'], 400),
        ]);

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->headers->get('Location');

        $this->assertStringContainsString('reason=token_exchange_failed', $location);
        $this->assertSame('error', $store->fresh()->connection_status->value);
        $this->assertNull($store->fresh()->shopify_access_token);
        $this->assertNotNull($state->fresh()->consumed_at);
    }

    public function test_identity_verification_failure_keeps_store_unconnected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        $state = ShopifyOauthState::query()->create([
            'state' => 'identity-fail-state',
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
                'errors' => [[
                    'message' => 'Access denied for shop field. Required access: read_something',
                    'extensions' => ['code' => 'ACCESS_DENIED'],
                ]],
            ], 200),
        ]);

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->headers->get('Location');

        $this->assertStringContainsString('reason=identity_permission_required', $location);
        $this->assertSame('error', $store->fresh()->connection_status->value);
        $this->assertFalse($store->fresh()->isConnected());
        $this->assertNull($store->fresh()->shopify_access_token);
    }

    public function test_duplicate_shop_ownership_is_rejected(): void
    {
        [$adminA, $workspaceA] = $this->adminWithWorkspace('A');
        Store::factory()->withShopifyCredentials('acme-shop.myshopify.com')
            ->create(['workspace_id' => $workspaceA->id]);

        [$adminB, $workspaceB] = $this->adminWithWorkspace('B');
        $storeB = Store::factory()->create([
            'workspace_id' => $workspaceB->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);

        $this->spa()->actingAs($adminB)
            ->getJson("/api/v1/stores/{$storeB->id}/shopify/oauth/start")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['domain']);

        $state = ShopifyOauthState::query()->create([
            'state' => 'dup-state',
            'user_id' => $adminB->id,
            'workspace_id' => $workspaceB->id,
            'store_id' => $storeB->id,
            'shop_domain' => 'acme-shop.myshopify.com',
            'expires_at' => now()->addMinutes(10),
        ]);

        $this->fakeSuccessfulShopifyHttp();

        $location = $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ))->headers->get('Location');

        $this->assertStringContainsString('reason=shop_already_owned', $location);
        $this->assertSame('error', $storeB->fresh()->connection_status->value);
        $this->assertNull($storeB->fresh()->shopify_access_token);
    }

    public function test_connection_status_stays_unconnected_until_identity_succeeds(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
        ]);
        $state = ShopifyOauthState::query()->create([
            'state' => 'partial-state',
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
                'data' => ['shop' => null],
            ], 200),
        ]);

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query(
            $this->signedCallbackQuery(['state' => $state->state])
        ));

        $this->assertNotSame('connected', $store->fresh()->connection_status->value);
        $this->assertFalse($store->fresh()->hasShopifyCredentials());
    }
}
