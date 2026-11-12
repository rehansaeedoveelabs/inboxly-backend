<?php

namespace Tests\Feature;

use App\Enums\StoreConnectionStatus;
use App\Exceptions\ShopifyWebhookRegistrationException;
use App\Jobs\RegisterShopifyWebhooksJob;
use App\Models\Contact;
use App\Models\ShopifyWebhookDelivery;
use App\Models\Store;
use App\Services\Shopify\ShopifyWebhookRegistrar;
use App\Support\Shopify\ShopifyHmac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyWebhookTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function connectedStore(string $domain = 'webhook-shop.myshopify.com'): Store
    {
        [, $workspace] = $this->adminWithWorkspace();

        return Store::factory()->withShopifyCredentials($domain)->create([
            'workspace_id' => $workspace->id,
        ])->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headerOverrides
     * @return array{0: string, 1: array<string, string>}
     */
    private function signedWebhook(
        array $payload,
        string $topic = 'app/uninstalled',
        string $shop = 'webhook-shop.myshopify.com',
        string $webhookId = 'delivery-1',
        array $headerOverrides = [],
    ): array {
        $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
        $hmac = base64_encode(hash_hmac(
            'sha256',
            $rawBody,
            (string) config('services.shopify.app_secret'),
            true,
        ));

        $headers = array_merge([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_API_VERSION' => '2026-10',
        ], $headerOverrides);

        return [$rawBody, $headers];
    }

    public function test_valid_hmac_uninstall_clears_credentials_and_preserves_audience(): void
    {
        $store = $this->connectedStore();
        Contact::factory()->create(['store_id' => $store->id]);

        [$body, $headers] = $this->signedWebhook([
            'id' => 548380009,
            'name' => 'Webhook Shop',
            'myshopify_domain' => 'webhook-shop.myshopify.com',
        ]);

        $response = $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body);

        $response->assertOk();

        $fresh = $store->fresh();
        $this->assertSame(StoreConnectionStatus::Disconnected, $fresh->connection_status);
        $this->assertNull($fresh->shopify_access_token);
        $this->assertNull($fresh->shopify_refresh_token);
        $this->assertSame('webhook-shop.myshopify.com', $fresh->shopify_shop_domain);
        $this->assertSame(1, $fresh->contacts()->count());
        $this->assertDatabaseHas('shopify_webhook_deliveries', [
            'webhook_id' => 'delivery-1',
            'topic' => 'app/uninstalled',
            'shop_domain' => 'webhook-shop.myshopify.com',
        ]);
    }

    public function test_invalid_hmac_is_rejected(): void
    {
        $this->connectedStore();

        [$body, $headers] = $this->signedWebhook(['id' => 1]);
        $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = base64_encode('not-the-real-digest-value!!!!!');

        $response = $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body);

        $response->assertUnauthorized();
        $this->assertDatabaseCount('shopify_webhook_deliveries', 0);
        $this->assertNotNull(Store::query()->first()->shopify_access_token);
    }

    public function test_malformed_hmac_is_rejected_without_throwing(): void
    {
        $this->assertFalse(ShopifyHmac::isValidWebhook('{}', '%%%', (string) config('services.shopify.app_secret')));
        $this->assertFalse(ShopifyHmac::isValidWebhook('{}', null, (string) config('services.shopify.app_secret')));
        $this->assertFalse(ShopifyHmac::isValidWebhook('{}', '', (string) config('services.shopify.app_secret')));
    }

    public function test_unknown_shop_is_acknowledged_without_side_effects(): void
    {
        [$body, $headers] = $this->signedWebhook(
            ['id' => 1],
            shop: 'unknown-shop.myshopify.com',
            webhookId: 'unknown-delivery',
        );

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertOk();

        $this->assertDatabaseHas('shopify_webhook_deliveries', [
            'webhook_id' => 'unknown-delivery',
            'store_id' => null,
        ]);
    }

    public function test_missing_headers_are_rejected(): void
    {
        $store = $this->connectedStore();
        [$body, $headers] = $this->signedWebhook(['id' => 1]);
        unset($headers['HTTP_X_SHOPIFY_TOPIC']);

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertStatus(400);

        $this->assertNotNull($store->fresh()->shopify_access_token);
        $this->assertDatabaseCount('shopify_webhook_deliveries', 0);
    }

    public function test_duplicate_delivery_is_idempotent(): void
    {
        $store = $this->connectedStore();
        [$body, $headers] = $this->signedWebhook(['id' => 1], webhookId: 'dup-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(1, ShopifyWebhookDelivery::query()->where('webhook_id', 'dup-1')->count());
        $this->assertSame(StoreConnectionStatus::Disconnected, $store->fresh()->connection_status);
    }

    public function test_concurrent_duplicate_delivery_claims_once(): void
    {
        $this->connectedStore();

        $first = ShopifyWebhookDelivery::query()->create([
            'webhook_id' => 'race-1',
            'topic' => 'app/uninstalled',
            'shop_domain' => 'webhook-shop.myshopify.com',
        ]);

        $this->assertNotNull($first);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ShopifyWebhookDelivery::query()->create([
            'webhook_id' => 'race-1',
            'topic' => 'app/uninstalled',
            'shop_domain' => 'webhook-shop.myshopify.com',
        ]);
    }

    public function test_scopes_update_replaces_stored_scopes_from_verified_payload(): void
    {
        $store = $this->connectedStore();
        $store->forceFill(['shopify_scopes' => ['read_products']])->save();

        [$body, $headers] = $this->signedWebhook(
            [
                'id' => 1,
                'shop_id' => 'gid://shopify/Shop/1',
                'previous' => ['read_products'],
                'current' => ['read_products', 'write_products'],
                'updated_at' => '2024-06-25T00:00:00.000Z',
            ],
            topic: 'app/scopes_update',
            webhookId: 'scopes-1',
        );

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(
            ['read_products', 'write_products'],
            $store->fresh()->shopify_scopes,
        );
        $this->assertNotNull($store->fresh()->shopify_access_token);
        $this->assertSame(StoreConnectionStatus::Connected, $store->fresh()->connection_status);
    }

    public function test_webhook_registration_success_and_skips_duplicates(): void
    {
        $uri = 'https://hooks.example.com/api/v1/shopify/webhooks';
        config(['services.shopify.webhook_uri' => $uri]);

        $store = $this->connectedStore();
        $listCalls = 0;
        $createCalls = 0;

        Http::fake(function (Request $request) use (&$listCalls, &$createCalls, $uri) {
            $payload = $request->data();
            $query = (string) ($payload['query'] ?? '');

            if (str_contains($query, 'query WebhookSubscriptions')) {
                $listCalls++;

                return Http::response([
                    'data' => [
                        'webhookSubscriptions' => [
                            'edges' => $listCalls > 1 ? [
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/WebhookSubscription/1',
                                        'topic' => 'APP_UNINSTALLED',
                                        'uri' => $uri,
                                    ],
                                ],
                                [
                                    'node' => [
                                        'id' => 'gid://shopify/WebhookSubscription/2',
                                        'topic' => 'APP_SCOPES_UPDATE',
                                        'uri' => $uri,
                                    ],
                                ],
                            ] : [],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($query, 'webhookSubscriptionCreate')) {
                $createCalls++;
                $topic = data_get($payload, 'variables.topic');

                return Http::response([
                    'data' => [
                        'webhookSubscriptionCreate' => [
                            'webhookSubscription' => [
                                'id' => 'gid://shopify/WebhookSubscription/'.$createCalls,
                                'topic' => $topic,
                                'uri' => $uri,
                            ],
                            'userErrors' => [],
                        ],
                    ],
                ], 200);
            }

            return Http::response(['errors' => [['message' => 'unexpected']]], 200);
        });

        $registrar = app(ShopifyWebhookRegistrar::class);
        $first = $registrar->registerRequiredTopics($store, (string) $store->shopify_access_token);
        $this->assertSame(['APP_UNINSTALLED', 'APP_SCOPES_UPDATE'], $first);
        $this->assertSame(2, $createCalls);

        $second = $registrar->registerRequiredTopics($store, (string) $store->shopify_access_token);
        $this->assertSame(['APP_UNINSTALLED', 'APP_SCOPES_UPDATE'], $second);
        $this->assertSame(2, $createCalls);
    }

    public function test_webhook_registration_transient_failure_is_retryable_and_keeps_credentials(): void
    {
        config(['services.shopify.webhook_uri' => 'https://hooks.example.com/api/v1/shopify/webhooks']);
        $store = $this->connectedStore();

        Http::fake([
            'https://webhook-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response('nope', 500),
        ]);

        try {
            app(ShopifyWebhookRegistrar::class)
                ->registerRequiredTopics($store, (string) $store->shopify_access_token);
            $this->fail('Expected ShopifyWebhookRegistrationException.');
        } catch (ShopifyWebhookRegistrationException $e) {
            $this->assertSame('webhook_transient_http', $e->reasonCode);
            $this->assertFalse($e->permanent);
        }

        $this->assertSame(StoreConnectionStatus::Connected, $store->fresh()->connection_status);
        $this->assertNotNull($store->fresh()->shopify_access_token);
        $this->assertStringNotContainsString('shpat_', json_encode($store->fresh()->toArray()) ?: '');
    }

    public function test_webhook_registration_rejects_localhost_uri_as_permanent(): void
    {
        config([
            'services.shopify.webhook_uri' => 'https://localhost/api/v1/shopify/webhooks',
            'services.shopify.webhook_allow_local' => false,
        ]);
        $store = $this->connectedStore();
        Http::fake();

        try {
            app(ShopifyWebhookRegistrar::class)
                ->registerRequiredTopics($store, (string) $store->shopify_access_token);
            $this->fail('Expected ShopifyWebhookRegistrationException.');
        } catch (ShopifyWebhookRegistrationException $e) {
            $this->assertSame('webhook_uri_invalid', $e->reasonCode);
            $this->assertTrue($e->permanent);
        }

        Http::assertNothingSent();
    }

    public function test_webhook_registration_requires_explicit_uri_without_app_url_fallback(): void
    {
        config([
            'services.shopify.webhook_uri' => '',
            'app.url' => 'https://hooks.example.com',
        ]);
        $store = $this->connectedStore();
        Http::fake();

        try {
            app(ShopifyWebhookRegistrar::class)
                ->registerRequiredTopics($store, (string) $store->shopify_access_token);
            $this->fail('Expected ShopifyWebhookRegistrationException.');
        } catch (ShopifyWebhookRegistrationException $e) {
            $this->assertSame('webhook_uri_missing', $e->reasonCode);
            $this->assertTrue($e->permanent);
        }

        Http::assertNothingSent();
    }

    public function test_registration_job_records_status_and_does_not_retry_permanent_failures(): void
    {
        config([
            'services.shopify.webhook_uri' => 'http://hooks.example.com/api/v1/shopify/webhooks',
        ]);
        $store = $this->connectedStore();

        $job = new RegisterShopifyWebhooksJob($store->getKey());
        $job->handle(
            app(ShopifyWebhookRegistrar::class),
            app(\App\Services\Shopify\ShopifyCredentialService::class),
        );

        $fresh = $store->fresh();
        $this->assertSame('failed', data_get($fresh->metadata, 'shopify_webhooks.status'));
        $this->assertSame('webhook_uri_invalid', data_get($fresh->metadata, 'shopify_webhooks.reason'));
        $this->assertSame('hooks.example.com', data_get($fresh->metadata, 'shopify_webhooks.uri_host'));
        $this->assertNotNull($fresh->shopify_access_token);
    }

    public function test_registration_job_rethrows_transient_failures_for_queue_retry(): void
    {
        config(['services.shopify.webhook_uri' => 'https://hooks.example.com/api/v1/shopify/webhooks']);
        $store = $this->connectedStore();

        Http::fake(function () {
            throw new ConnectionException('timeout');
        });

        $job = new RegisterShopifyWebhooksJob($store->getKey());

        try {
            $job->handle(
                app(ShopifyWebhookRegistrar::class),
                app(\App\Services\Shopify\ShopifyCredentialService::class),
            );
            $this->fail('Expected ShopifyWebhookRegistrationException.');
        } catch (ShopifyWebhookRegistrationException $e) {
            $this->assertFalse($e->permanent);
            $this->assertSame('webhook_network', $e->reasonCode);
        }

        $this->assertSame('failed', data_get($store->fresh()->metadata, 'shopify_webhooks.status'));
        $this->assertSame(['15', '60', '180', '300'], array_map('strval', $job->backoff()));
    }

    public function test_oauth_success_dispatches_webhook_registration_job(): void
    {
        Queue::fake();

        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'acme-shop.myshopify.com',
            'connection_status' => StoreConnectionStatus::Pending,
        ]);

        $state = \App\Models\ShopifyOauthState::query()->create([
            'state' => bin2hex(random_bytes(32)),
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
                'data' => [
                    'shop' => [
                        'id' => 'gid://shopify/Shop/123',
                        'name' => 'Acme',
                        'myshopifyDomain' => 'acme-shop.myshopify.com',
                        'currencyCode' => 'USD',
                        'ianaTimezone' => 'UTC',
                    ],
                ],
            ], 200),
        ]);

        $params = [
            'code' => 'shopify-auth-code',
            'shop' => 'acme-shop.myshopify.com',
            'state' => $state->state,
            'timestamp' => (string) now()->timestamp,
        ];
        ksort($params);
        $message = collect($params)->map(fn ($v, $k) => $k.'='.$v)->implode('&');
        $params['hmac'] = hash_hmac('sha256', $message, (string) config('services.shopify.app_secret'));

        $this->get('/api/v1/shopify/oauth/callback?'.http_build_query($params))
            ->assertRedirect();

        Queue::assertPushed(RegisterShopifyWebhooksJob::class, function (RegisterShopifyWebhooksJob $job) use ($store) {
            return $job->storeId === $store->getKey();
        });

        $this->assertSame(StoreConnectionStatus::Connected, $store->fresh()->connection_status);
    }

    public function test_webhook_response_never_includes_tokens(): void
    {
        $this->connectedStore();
        [$body, $headers] = $this->signedWebhook(['id' => 1], webhookId: 'safe-1');

        $response = $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body);

        $response->assertOk();
        $this->assertSame('', $response->getContent());
        $this->assertStringNotContainsString('shpat_', $response->getContent());
        $this->assertStringNotContainsString('shprt_', $response->getContent());
    }
}
