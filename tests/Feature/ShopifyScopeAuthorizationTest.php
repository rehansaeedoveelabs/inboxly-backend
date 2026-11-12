<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Exceptions\ShopifyCredentialException;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\Store;
use App\Services\Shopify\ShopifyCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyScopeAuthorizationTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_required_and_requested_scopes_are_configured(): void
    {
        $required = config('services.shopify.required_scopes');
        $scopes = config('services.shopify.scopes');

        $this->assertSame([
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ], $required);
        $this->assertSame($required, $scopes);
    }

    public function test_oauth_authorize_url_includes_configured_scopes(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'scopes-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $location = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->headers->get('Location');

        $this->assertNotNull($location);
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame(
            'read_customers,read_products,read_orders,read_all_orders',
            $query['scope'] ?? null,
        );
    }

    public function test_unset_requested_scopes_resolve_to_required_baseline(): void
    {
        $required = [
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ];

        // Mirror config/services.php: env(SHOPIFY_SCOPES) null → required baseline.
        $resolved = \App\Support\Shopify\ShopifyScopes::requestedFromEnv(null, $required);
        $this->assertSame($required, $resolved);
        $this->assertSame($required, config('services.shopify.required_scopes'));
        // phpunit forces SHOPIFY_SCOPES to the documented unset default.
        $this->assertSame($required, config('services.shopify.scopes'));
    }

    public function test_explicitly_empty_scopes_cannot_generate_oauth_url(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'empty-scopes.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        config(['services.shopify.scopes' => []]);

        $response = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start");

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['shopify']);

        $this->assertNull($response->headers->get('Location'));
        $this->assertSame(0, \App\Models\ShopifyOauthState::query()->count());
    }

    public function test_whitespace_only_scopes_cannot_generate_oauth_url(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'ws-scopes.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        config([
            'services.shopify.scopes' => \App\Support\Shopify\ShopifyScopes::requestedFromEnv(
                '  , ',
                config('services.shopify.required_scopes'),
            ),
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shopify']);
    }

    public function test_custom_scopes_are_represented_in_oauth_url(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'custom-scopes.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        config([
            'services.shopify.scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
                'read_locations',
            ],
        ]);

        $location = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->headers->get('Location');

        $this->assertNotNull($location);
        parse_str(parse_url($location, PHP_URL_QUERY) ?: '', $query);
        $this->assertSame(
            'read_customers,read_products,read_orders,read_all_orders,read_locations',
            $query['scope'] ?? null,
        );
    }

    public function test_requested_scopes_missing_required_are_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'incomplete-scopes.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        config([
            'services.shopify.scopes' => ['read_products', 'read_orders'],
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/oauth/start")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shopify']);

        $this->assertSame(0, \App\Models\ShopifyOauthState::query()->count());
    }

    public function test_granted_scopes_from_token_response_are_persisted_not_assumed(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'grant-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $start = $this->spa()->actingAs($admin)
            ->get("/api/v1/stores/{$store->id}/shopify/oauth/start");
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY) ?: '', $query);

        Http::fake([
            'https://grant-shop.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_granted_only',
                'refresh_token' => 'shprt_granted_only',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                // Shopify returns a subset — Inboxly must not invent the rest.
                'scope' => 'read_customers,read_products',
            ], 200),
            'https://grant-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'shop' => [
                        'id' => 'gid://shopify/Shop/99',
                        'name' => 'Grant Shop',
                        'myshopifyDomain' => 'grant-shop.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'UTC',
                    ],
                ],
            ], 200),
        ]);

        $params = [
            'code' => 'auth-code',
            'shop' => 'grant-shop.myshopify.com',
            'state' => $query['state'],
            'timestamp' => (string) now()->timestamp,
        ];
        ksort($params);
        $message = collect($params)->map(fn ($v, $k) => $k.'='.$v)->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));

        Queue::fake();
        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($params))
            ->assertRedirect();

        $fresh = $store->fresh();
        $this->assertSame(['read_customers', 'read_products'], $fresh->shopify_scopes);
        $this->assertSame(
            ['read_orders', 'read_all_orders'],
            $fresh->missingShopifyScopes(),
        );
        $this->assertTrue($fresh->requiresShopifyReauth());
        $this->assertSame(StoreConnectionStatus::Connected, $fresh->connection_status);
        $this->assertTrue($fresh->hasShopifyCredentials());
    }

    public function test_connected_store_missing_new_permissions_stays_connected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('legacy-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'shopify_scopes' => ['read_products'],
        ]);

        $this->assertTrue($store->isConnected());
        $this->assertTrue($store->requiresShopifyReauth());
        $this->assertContains('read_customers', $store->missingShopifyScopes());
        $this->assertContains('read_orders', $store->missingShopifyScopes());
        $this->assertContains('read_all_orders', $store->missingShopifyScopes());

        $payload = $this->spa()->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$workspace->id}/stores")
            ->assertOk()
            ->json('data.0');

        $this->assertTrue($payload['is_connected']);
        $this->assertTrue($payload['requires_shopify_reauth']);
        $this->assertSame(['read_products'], $payload['shopify_scopes']);
        $this->assertEqualsCanonicalizing(
            ['read_customers', 'read_orders', 'read_all_orders'],
            $payload['missing_shopify_scopes'],
        );
        $this->assertArrayNotHasKey('shopify_access_token', $payload);
        $this->assertArrayNotHasKey('shopify_refresh_token', $payload);
        $encoded = json_encode($payload);
        $this->assertStringNotContainsString('shpat_', $encoded);
        $this->assertStringNotContainsString('shprt_', $encoded);
    }

    public function test_live_access_scopes_query_returns_handles(): void
    {
        $store = Store::factory()->withShopifyCredentials('live-scopes.myshopify.com')->create([
            'shopify_scopes' => [],
            'shopify_access_token_expires_at' => now()->addHour(),
            'shopify_refresh_token_expires_at' => now()->addDays(30),
        ]);

        Http::fake([
            'https://live-scopes.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'currentAppInstallation' => [
                        'accessScopes' => [
                            ['handle' => 'read_customers'],
                            ['handle' => 'read_products'],
                            ['handle' => 'read_orders'],
                            ['handle' => 'read_all_orders'],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $handles = app(ShopifyCredentialService::class)->fetchLiveAccessScopes($store);

        $this->assertSame([
            'read_customers',
            'read_products',
            'read_orders',
            'read_all_orders',
        ], $handles);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) ($body['query'] ?? ''), 'currentAppInstallation')
                && str_contains((string) ($body['query'] ?? ''), 'accessScopes')
                && $request->hasHeader('X-Shopify-Access-Token')
                && ! str_contains(json_encode($body), 'shprt_');
        });
    }

    public function test_access_scopes_graphql_access_denied_is_permanent(): void
    {
        $store = Store::factory()->withShopifyCredentials('denied-scopes.myshopify.com')->create([
            'shopify_access_token_expires_at' => now()->addHour(),
            'shopify_refresh_token_expires_at' => now()->addDays(30),
        ]);

        Http::fake([
            'https://denied-scopes.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'errors' => [
                    [
                        'message' => 'Access denied for accessScopes field.',
                        'extensions' => ['code' => 'ACCESS_DENIED'],
                    ],
                ],
            ], 200),
        ]);

        try {
            app(ShopifyCredentialService::class)->fetchLiveAccessScopes($store);
            $this->fail('Expected ShopifyCredentialException.');
        } catch (ShopifyCredentialException $e) {
            $this->assertSame('access_scopes_denied', $e->reasonCode);
            $this->assertTrue($e->permanent);
        }

        // Missing-scope GraphQL denial must not clear an otherwise valid connection.
        $this->assertTrue($store->fresh()->hasShopifyCredentials());
        $this->assertSame(StoreConnectionStatus::Connected, $store->fresh()->connection_status);
    }

    public function test_webhook_registration_refreshes_expired_access_token_first(): void
    {
        $webhookUri = 'https://inboxly-hooks.example.com/api/v1/shopify/webhooks';
        config([
            'services.shopify.webhook_uri' => $webhookUri,
            // .example hosts are blocked unless local escape hatch is on (tests only).
            'services.shopify.webhook_allow_local' => true,
        ]);

        $store = Store::factory()->withShopifyCredentials('refresh-hooks.myshopify.com')->create([
            'shopify_access_token_expires_at' => now()->subMinute(),
            'shopify_refresh_token_expires_at' => now()->addDays(30),
        ]);
        $store->forceFill([
            'shopify_access_token' => 'shpat_expired_token',
            'shopify_refresh_token' => 'shprt_refresh_token',
        ])->save();

        Http::fake([
            'https://refresh-hooks.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_rotated_token',
                'refresh_token' => 'shprt_rotated_token',
                'expires_in' => 3600,
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_customers,read_products,read_orders,read_all_orders',
            ], 200),
            'https://refresh-hooks.myshopify.com/admin/api/2026-10/graphql.json' => function ($request) use ($webhookUri) {
                static $n = 0;
                $n++;
                if ($n === 1) {
                    return Http::response([
                        'data' => ['webhookSubscriptions' => ['edges' => []]],
                    ], 200);
                }
                $topic = $n === 2 ? 'APP_UNINSTALLED' : 'APP_SCOPES_UPDATE';

                return Http::response([
                    'data' => [
                        'webhookSubscriptionCreate' => [
                            'webhookSubscription' => [
                                'id' => 'gid://shopify/WebhookSubscription/'.$n,
                                'topic' => $topic,
                                'uri' => $webhookUri,
                            ],
                            'userErrors' => [],
                        ],
                    ],
                ], 200);
            },
        ]);

        (new RegisterShopifyWebhooksJob($store->id))->handle(
            app(\App\Services\Shopify\ShopifyWebhookRegistrar::class),
            app(ShopifyCredentialService::class),
        );

        $this->assertSame('shpat_rotated_token', $store->fresh()->shopify_access_token);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/admin/api/2026-10/graphql.json')) {
                return false;
            }

            return ($request->header('X-Shopify-Access-Token')[0] ?? null) === 'shpat_rotated_token';
        });

        Http::assertNotSent(function ($request) {
            if (! str_contains($request->url(), '/admin/api/2026-10/graphql.json')) {
                return false;
            }

            return ($request->header('X-Shopify-Access-Token')[0] ?? null) === 'shpat_expired_token';
        });
    }

    public function test_store_with_all_required_scopes_does_not_require_reauth(): void
    {
        $store = Store::factory()->withShopifyCredentials('complete-shop.myshopify.com')->create([
            'shopify_scopes' => [
                'read_customers',
                'read_products',
                'read_orders',
                'read_all_orders',
            ],
        ]);

        $this->assertSame([], $store->missingShopifyScopes());
        $this->assertFalse($store->requiresShopifyReauth());
    }
}
