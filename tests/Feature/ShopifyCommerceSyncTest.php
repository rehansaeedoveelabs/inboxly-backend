<?php

namespace Tests\Feature;

use App\Enums\ShopifyCustomerIdentityStatus;
use App\Enums\ShopifySyncRunStatus;
use App\Jobs\RunShopifyCommerceSyncJob;
use App\Models\Contact;
use App\Models\ShopifyCustomerIdentity;
use App\Models\ShopifyOrder;
use App\Models\ShopifyProduct;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyCommerceSyncTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    /**
     * @return array{0: \App\Models\User, 1: Store}
     */
    private function connectedStore(array $scopes = ['read_customers', 'read_products', 'read_orders', 'read_all_orders']): array
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('sync-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'shopify_scopes' => $scopes,
        ]);

        return [$admin, $store];
    }

    public function test_start_sync_creates_run_and_dispatches_job(): void
    {
        Queue::fake();
        [$admin, $store] = $this->connectedStore();

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync", [
                'resources' => ['customers', 'products', 'orders'],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.resources.0', 'customers');

        $this->assertDatabaseCount('shopify_sync_runs', 1);
        Queue::assertPushed(RunShopifyCommerceSyncJob::class);
    }

    public function test_missing_scope_rejects_start(): void
    {
        Queue::fake();
        [$admin, $store] = $this->connectedStore(['read_products']);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync", [
                'resources' => ['customers', 'products'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['resources']);

        Queue::assertNothingPushed();
    }

    public function test_job_upserts_customers_products_orders_and_materializes_contacts(): void
    {
        [$admin, $store] = $this->connectedStore();

        Http::fake([
            'https://sync-shop.myshopify.com/admin/api/2026-10/graphql.json' => function ($request) {
                $body = $request->body();
                if (str_contains($body, 'SyncCustomers')) {
                    return Http::response([
                        'data' => [
                            'customers' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Customer/1001',
                                            'updatedAt' => '2026-11-18T10:00:00Z',
                                            'firstName' => 'Sam',
                                            'lastName' => 'Merchant',
                                            'defaultEmailAddress' => ['emailAddress' => 'sam.merchant@example.com'],
                                            'defaultPhoneNumber' => ['phoneNumber' => '+15555550100'],
                                        ],
                                    ],
                                ],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ]);
                }
                if (str_contains($body, 'SyncProducts')) {
                    return Http::response([
                        'data' => [
                            'products' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Product/2001',
                                            'title' => 'Honey Jar',
                                            'handle' => 'honey-jar',
                                            'status' => 'ACTIVE',
                                            'productType' => 'Food',
                                            'vendor' => 'Inboxly',
                                            'updatedAt' => '2026-11-18T11:00:00Z',
                                            'featuredImage' => ['url' => 'https://cdn.example/honey.jpg'],
                                            'variantsCount' => ['count' => 2],
                                        ],
                                    ],
                                ],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ]);
                }
                if (str_contains($body, 'SyncOrders')) {
                    return Http::response([
                        'data' => [
                            'orders' => [
                                'edges' => [
                                    [
                                        'node' => [
                                            'id' => 'gid://shopify/Order/3001',
                                            'name' => '#1001',
                                            'legacyResourceId' => '3001',
                                            'displayFinancialStatus' => 'PAID',
                                            'displayFulfillmentStatus' => 'UNFULFILLED',
                                            'processedAt' => '2026-11-17T12:00:00Z',
                                            'updatedAt' => '2026-11-18T12:00:00Z',
                                            'totalPriceSet' => [
                                                'shopMoney' => [
                                                    'amount' => '42.50',
                                                    'currencyCode' => 'USD',
                                                ],
                                            ],
                                            'customer' => ['id' => 'gid://shopify/Customer/1001'],
                                        ],
                                    ],
                                ],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ]);
                }

                return Http::response(['errors' => [['message' => 'unexpected query']]], 200);
            },
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync")
            ->assertStatus(202);

        $run = ShopifySyncRun::query()->first();
        $this->assertNotNull($run);
        (new RunShopifyCommerceSyncJob($run->getKey()))->handle(
            app(\App\Services\Shopify\ShopifyCredentialService::class),
            app(\App\Actions\Shopify\SyncShopifyCommerceResourcePage::class),
        );

        $run->refresh();
        $this->assertSame(ShopifySyncRunStatus::Succeeded, $run->status);
        $this->assertSame(1, $run->customers_synced);
        $this->assertSame(1, $run->products_synced);
        $this->assertSame(1, $run->orders_synced);

        $identity = ShopifyCustomerIdentity::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($identity);
        $this->assertSame('gid://shopify/Customer/1001', $identity->shopify_customer_gid);
        $this->assertSame(ShopifyCustomerIdentityStatus::Tracked, $identity->status);
        $this->assertNotNull($identity->contact_id);

        $contact = Contact::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($contact);
        $this->assertSame('sam.merchant@example.com', $contact->email);
        $this->assertSame('Sam', $contact->first_name);
        $this->assertSame('shopify', $contact->source);
        $this->assertSame($contact->id, $identity->contact_id);

        $product = ShopifyProduct::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($product);
        $this->assertSame('Honey Jar', $product->title);
        $this->assertSame('honey-jar', $product->handle);
        $this->assertSame('https://cdn.example/honey.jpg', $product->metadata['featured_image_url'] ?? null);

        $order = ShopifyOrder::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('#1001', $order->name);
        $this->assertSame('gid://shopify/Customer/1001', $order->shopify_customer_gid);
        $this->assertSame('42.50', (string) $order->total_price);
        $this->assertSame('USD', $order->currency_code);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/sync")
            ->assertOk()
            ->assertJsonPath('data.summary.status', 'succeeded')
            ->assertJsonPath('data.counts.customer_identities', 1)
            ->assertJsonPath('data.counts.products', 1)
            ->assertJsonPath('data.counts.orders', 1);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/workspaces/{$store->workspace_id}/stores")
            ->assertOk()
            ->assertJsonPath('data.0.shopify_sync.status', 'succeeded');
    }

    public function test_customer_sync_skips_phone_when_owned_by_another_contact(): void
    {
        [$admin, $store] = $this->connectedStore();

        Contact::factory()->create([
            'store_id' => $store->id,
            'email' => 'existing@example.com',
            'phone' => '+15555550100',
            'source' => 'manual',
        ]);

        Http::fake([
            'https://sync-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'customers' => [
                        'edges' => [
                            [
                                'node' => [
                                    'id' => 'gid://shopify/Customer/1002',
                                    'updatedAt' => '2026-11-18T10:00:00Z',
                                    'firstName' => 'New',
                                    'lastName' => 'Customer',
                                    'defaultEmailAddress' => ['emailAddress' => 'new.customer@example.com'],
                                    'defaultPhoneNumber' => ['phoneNumber' => '+15555550100'],
                                ],
                            ],
                        ],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ],
                ],
            ]),
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync", [
                'resources' => ['customers'],
            ])
            ->assertStatus(202);

        $run = ShopifySyncRun::query()->first();
        $this->assertNotNull($run);
        (new RunShopifyCommerceSyncJob($run->getKey()))->handle(
            app(\App\Services\Shopify\ShopifyCredentialService::class),
            app(\App\Actions\Shopify\SyncShopifyCommerceResourcePage::class),
        );

        $run->refresh();
        $this->assertSame(ShopifySyncRunStatus::Succeeded, $run->status);

        $contact = Contact::query()->where('store_id', $store->id)->where('email', 'new.customer@example.com')->first();
        $this->assertNotNull($contact);
        $this->assertNull($contact->phone);
        $this->assertSame('shopify', $contact->source);

        $identity = ShopifyCustomerIdentity::query()->where('store_id', $store->id)->first();
        $this->assertNotNull($identity);
        $this->assertSame($contact->id, $identity->contact_id);
    }

    public function test_pagination_continues_via_redispatch(): void
    {
        config([
            'services.shopify.sync_page_size' => 1,
            'services.shopify.sync_max_pages_per_attempt' => 1,
        ]);

        [$admin, $store] = $this->connectedStore();

        $customerCalls = 0;
        Http::fake([
            'https://sync-shop.myshopify.com/admin/api/2026-10/graphql.json' => function ($request) use (&$customerCalls) {
                $body = $request->body();
                if (str_contains($body, 'SyncCustomers')) {
                    $customerCalls++;
                    if ($customerCalls === 1) {
                        return Http::response([
                            'data' => [
                                'customers' => [
                                    'edges' => [
                                        ['node' => ['id' => 'gid://shopify/Customer/1', 'updatedAt' => '2026-11-18T10:00:00Z']],
                                    ],
                                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                                ],
                            ],
                        ]);
                    }

                    return Http::response([
                        'data' => [
                            'customers' => [
                                'edges' => [
                                    ['node' => ['id' => 'gid://shopify/Customer/2', 'updatedAt' => '2026-11-18T10:01:00Z']],
                                ],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ]);
                }

                // Other resources empty.
                $field = str_contains($body, 'SyncProducts') ? 'products' : 'orders';

                return Http::response([
                    'data' => [
                        $field => [
                            'edges' => [],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
            },
        ]);

        Queue::fake();
        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync", [
                'resources' => ['customers'],
            ])
            ->assertStatus(202);

        $run = ShopifySyncRun::query()->first();
        $this->assertNotNull($run);

        // First attempt: one page, then re-dispatch.
        (new RunShopifyCommerceSyncJob($run->getKey()))->handle(
            app(\App\Services\Shopify\ShopifyCredentialService::class),
            app(\App\Actions\Shopify\SyncShopifyCommerceResourcePage::class),
        );

        $run->refresh();
        $this->assertSame(1, $run->customers_synced);
        $this->assertSame('cursor-1', $run->customers_cursor);
        $this->assertNull($run->finished_at);

        Queue::assertPushed(RunShopifyCommerceSyncJob::class, 2);

        // Second attempt: finish.
        (new RunShopifyCommerceSyncJob($run->getKey()))->handle(
            app(\App\Services\Shopify\ShopifyCredentialService::class),
            app(\App\Actions\Shopify\SyncShopifyCommerceResourcePage::class),
        );

        $run->refresh();
        $this->assertSame(ShopifySyncRunStatus::Succeeded, $run->status);
        $this->assertSame(2, $run->customers_synced);
        $this->assertSame(2, ShopifyCustomerIdentity::query()->where('store_id', $store->id)->count());
    }

    public function test_concurrent_start_is_conflict(): void
    {
        Queue::fake();
        [$admin, $store] = $this->connectedStore();

        ShopifySyncRun::factory()->create([
            'store_id' => $store->id,
            'status' => ShopifySyncRunStatus::Running,
            'lease_expires_at' => now()->addMinutes(2),
            'resources' => ['products'],
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/sync")
            ->assertStatus(409);
    }
}
