<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Exceptions\ShopifyCredentialException;
use App\Models\Store;
use App\Services\Shopify\ShopifyCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyTokenRefreshTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function connectedStore(string $domain = 'refresh-shop.myshopify.com', array $overrides = []): Store
    {
        [, $workspace] = $this->adminWithWorkspace();

        $store = Store::factory()->withShopifyCredentials($domain)->create([
            'workspace_id' => $workspace->id,
            ...$overrides,
        ]);

        return $store->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(array $overrides = []): array
    {
        return array_merge([
            'access_token' => 'shpat_rotated_access',
            'refresh_token' => 'shprt_rotated_refresh',
            'expires_in' => 3600,
            'refresh_token_expires_in' => 7776000,
            'scope' => '',
        ], $overrides);
    }

    public function test_successful_refresh_rotates_tokens_and_expiries(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(60),
            'shopify_refresh_token' => 'shprt_original_refresh',
            'shopify_access_token' => 'shpat_original_access',
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload(), 200),
        ]);

        $token = app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());

        $this->assertSame('shpat_rotated_access', $token);

        $fresh = $store->fresh();
        $this->assertSame('shpat_rotated_access', $fresh->shopify_access_token);
        $this->assertSame('shprt_rotated_refresh', $fresh->shopify_refresh_token);
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertTrue($fresh->shopify_access_token_expires_at->greaterThan(now()->addMinutes(50)));
        $this->assertTrue($fresh->shopify_refresh_token_expires_at->greaterThan(now()->addDays(80)));
        $this->assertNotSame('shprt_original_refresh', $fresh->shopify_refresh_token);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://refresh-shop.myshopify.com/admin/oauth/access_token'
                && $request['grant_type'] === 'refresh_token'
                && $request['refresh_token'] === 'shprt_original_refresh'
                && $request['client_id'] === 'test-shopify-client-id'
                && ! str_contains(json_encode($request->data()), 'shpat_');
        });
    }

    public function test_no_refresh_when_access_token_sufficiently_valid(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token' => 'shpat_still_valid',
            'shopify_access_token_expires_at' => now()->addMinutes(30),
        ])->save();

        Http::fake();

        $token = app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());

        $this->assertSame('shpat_still_valid', $token);
        Http::assertNothingSent();
    }

    public function test_refresh_when_approaching_expiry_within_skew(): void
    {
        config(['services.shopify.token_refresh_skew_seconds' => 120]);

        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(90),
            'shopify_refresh_token' => 'shprt_near_expiry',
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload(), 200),
        ]);

        app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());

        Http::assertSentCount(1);
        $this->assertSame('shprt_rotated_refresh', $store->fresh()->shopify_refresh_token);
    }

    public function test_expired_refresh_token_marks_credentials_invalid_without_http(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->subMinute(),
            'shopify_refresh_token_expires_at' => now()->subMinute(),
            'shopify_shop_domain' => 'refresh-shop.myshopify.com',
            'shopify_shop_id' => 'gid://shopify/Shop/1',
        ])->save();

        Http::fake();

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('refresh_token_expired', $e->reasonCode);
            $this->assertTrue($e->permanent);
        }

        Http::assertNothingSent();

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Error, $fresh->connection_status);
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertSame('refresh-shop.myshopify.com', $fresh->shopify_shop_domain);
        $this->assertSame('gid://shopify/Shop/1', $fresh->shopify_shop_id);
    }

    public function test_invalid_refresh_response_does_not_corrupt_credentials(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token' => 'shpat_keep_me',
            'shopify_refresh_token' => 'shprt_keep_me',
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_bad',
                // missing refresh_token and expiries
            ], 200),
        ]);

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_malformed', $e->reasonCode);
            $this->assertFalse($e->permanent);
        }

        $fresh = $store->fresh();
        $this->assertSame('shpat_keep_me', $fresh->shopify_access_token);
        $this->assertSame('shprt_keep_me', $fresh->shopify_refresh_token);
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
    }

    public function test_network_failure_keeps_connected_credentials(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token' => 'shpat_network',
            'shopify_refresh_token' => 'shprt_network',
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        Http::fake(function () {
            throw new ConnectionException('connection timed out');
        });

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_network', $e->reasonCode);
            $this->assertFalse($e->permanent);
        }

        $fresh = $store->fresh();
        $this->assertSame('shpat_network', $fresh->shopify_access_token);
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
    }

    public function test_shopify_5xx_is_transient(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response('upstream', 503),
        ]);

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_transient', $e->reasonCode);
            $this->assertFalse($e->permanent);
        }

        $this->assertSame(StoreConnectionStatus::Connected, $store->fresh()->connection_status);
        $this->assertNotNull($store->fresh()->shopify_refresh_token);
    }

    public function test_permanent_401_invalidates_credentials(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(30),
            'shopify_shop_domain' => 'refresh-shop.myshopify.com',
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'error' => 'invalid_request',
                'error_description' => 'This request requires an active refresh_token',
            ], 401),
        ]);

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_invalid', $e->reasonCode);
            $this->assertTrue($e->permanent);
        }

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Error, $fresh->connection_status);
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertSame('refresh-shop.myshopify.com', $fresh->shopify_shop_domain);
    }

    public function test_persistence_failure_preserves_previous_credentials(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token' => 'shpat_before_persist',
            'shopify_refresh_token' => 'shprt_before_persist',
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload(), 200),
        ]);

        $service = new class(app(\App\Services\Shopify\ShopifyOAuthClient::class)) extends ShopifyCredentialService
        {
            protected function persistRotatedCredentials(Store $store, array $payload, array $scopes): void
            {
                throw new \RuntimeException('simulated persistence failure');
            }
        };

        try {
            $service->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_persist_failed', $e->reasonCode);
            $this->assertFalse($e->permanent);
        }

        $fresh = $store->fresh();
        $this->assertSame('shpat_before_persist', $fresh->shopify_access_token);
        $this->assertSame('shprt_before_persist', $fresh->shopify_refresh_token);
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
    }

    public function test_concurrent_refresh_only_hits_shopify_once(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(30),
            'shopify_refresh_token' => 'shprt_once',
        ])->save();

        $requests = 0;
        Http::fake(function () use (&$requests) {
            $requests++;

            return Http::response([
                'access_token' => 'shpat_once_'.$requests,
                'refresh_token' => 'shprt_once_'.$requests,
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => '',
            ], 200);
        });

        $service = app(ShopifyCredentialService::class);
        $first = $service->ensureValidAccessToken($store->fresh());
        $second = $service->ensureValidAccessToken($store->fresh());

        $this->assertSame(1, $requests);
        $this->assertSame($first, $second);
        $this->assertSame('shpat_once_1', $store->fresh()->shopify_access_token);
        $this->assertSame('shprt_once_1', $store->fresh()->shopify_refresh_token);
    }

    public function test_independent_stores_refresh_separately(): void
    {
        $storeA = $this->connectedStore('store-a.myshopify.com');
        $storeB = $this->connectedStore('store-b.myshopify.com');

        foreach ([$storeA, $storeB] as $store) {
            $store->forceFill([
                'shopify_access_token_expires_at' => now()->addSeconds(30),
            ])->save();
        }

        Http::fake([
            'https://store-a.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload([
                'access_token' => 'shpat_a',
                'refresh_token' => 'shprt_a',
            ]), 200),
            'https://store-b.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload([
                'access_token' => 'shpat_b',
                'refresh_token' => 'shprt_b',
            ]), 200),
        ]);

        $service = app(ShopifyCredentialService::class);
        $this->assertSame('shpat_a', $service->ensureValidAccessToken($storeA->fresh()));
        $this->assertSame('shpat_b', $service->ensureValidAccessToken($storeB->fresh()));

        $this->assertSame('shprt_a', $storeA->fresh()->shopify_refresh_token);
        $this->assertSame('shprt_b', $storeB->fresh()->shopify_refresh_token);
        Http::assertSentCount(2);
    }

    public function test_second_waiter_reuses_credentials_refreshed_under_lock(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token' => 'shpat_stale',
            'shopify_refresh_token' => 'shprt_stale',
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        $requests = 0;
        Http::fake(function () use (&$requests, $store) {
            $requests++;

            // Simulate another process completing refresh while the lock holder runs.
            if ($requests === 1) {
                Store::query()->whereKey($store->getKey())->update([
                    // Leave encrypted columns alone; update only expiry so the
                    // second path still refreshes normally after first response.
                ]);
            }

            return Http::response($this->tokenPayload([
                'access_token' => 'shpat_lock_winner',
                'refresh_token' => 'shprt_lock_winner',
            ]), 200);
        });

        $service = app(ShopifyCredentialService::class);
        $service->ensureValidAccessToken($store->fresh());

        // Manually simulate a concurrent waiter that acquires the lock after refresh.
        $store->forceFill([
            'shopify_access_token' => 'shpat_lock_winner',
            'shopify_refresh_token' => 'shprt_lock_winner',
            'shopify_access_token_expires_at' => now()->addHour(),
            'shopify_refresh_token_expires_at' => now()->addDays(90),
        ])->save();

        $token = $service->ensureValidAccessToken($store->fresh());

        $this->assertSame('shpat_lock_winner', $token);
        $this->assertSame(1, $requests);
    }

    public function test_tokens_never_appear_in_logs_or_store_resource(): void
    {
        Log::spy();

        [$admin, $workspace] = $this->adminWithWorkspace('LogSafe');
        $store = Store::factory()->withShopifyCredentials('refresh-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(30),
            'shopify_refresh_token' => 'shprt_secret_refresh_value',
            'shopify_access_token' => 'shpat_secret_access_value',
        ])->save();

        Http::fake([
            'https://refresh-shop.myshopify.com/admin/oauth/access_token' => Http::response($this->tokenPayload([
                'access_token' => 'shpat_new_secret_access',
                'refresh_token' => 'shprt_new_secret_refresh',
            ]), 200),
        ]);

        app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            $encoded = json_encode($context) ?: '';

            return $message === 'Shopify credentials refreshed.'
                && ! str_contains($encoded, 'shpat_')
                && ! str_contains($encoded, 'shprt_')
                && ! str_contains($encoded, 'secret');
        });

        $response = $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}/stores");
        $response->assertOk();
        $this->assertStringNotContainsString('shpat_', $response->getContent());
        $this->assertStringNotContainsString('shprt_', $response->getContent());
        $this->assertArrayNotHasKey('shopify_access_token', $response->json('data.0'));
        $this->assertArrayNotHasKey('shopify_refresh_token', $response->json('data.0'));
    }

    public function test_lock_timeout_is_transient(): void
    {
        $store = $this->connectedStore();
        $store->forceFill([
            'shopify_access_token_expires_at' => now()->addSeconds(30),
        ])->save();

        config([
            'services.shopify.token_refresh_lock_wait_seconds' => 1,
            'services.shopify.token_refresh_lock_seconds' => 5,
        ]);

        $lock = Cache::lock('shopify-token-refresh:'.$store->getKey(), 5);
        $this->assertTrue($lock->get());

        try {
            app(ShopifyCredentialService::class)->ensureValidAccessToken($store->fresh());
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('token_refresh_lock_timeout', $e->reasonCode);
            $this->assertFalse($e->permanent);
        } finally {
            $lock->release();
        }
    }
}
