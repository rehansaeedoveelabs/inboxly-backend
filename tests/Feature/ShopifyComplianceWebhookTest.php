<?php

namespace Tests\Feature;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Enums\StoreConnectionStatus;
use App\Jobs\ProcessShopifyComplianceRequestJob;
use App\Models\Contact;
use App\Models\ContactConsent;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyWebhookDelivery;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyComplianceWebhookTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    private function connectedStore(string $domain = 'compliance-shop.myshopify.com'): Store
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
        string $topic,
        string $shop = 'compliance-shop.myshopify.com',
        string $webhookId = 'compliance-delivery-1',
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

    public function test_invalid_hmac_is_rejected_for_compliance_topic(): void
    {
        $this->connectedStore();
        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 1, 'email' => 'a@example.com'],
            'data_request' => ['id' => 9],
        ], 'customers/data_request');
        $headers['HTTP_X_SHOPIFY_HMAC_SHA256'] = base64_encode('not-the-real-digest-value!!!!!');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertUnauthorized();

        $this->assertSame(0, ShopifyComplianceRequest::query()->count());
    }

    public function test_data_request_creates_request_and_completes_inventory(): void
    {
        Storage::fake('local');
        $store = $this->connectedStore();
        Contact::factory()->create([
            'store_id' => $store->id,
            'email' => 'john@example.com',
        ]);

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 954889,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'orders_requested' => [1],
            'customer' => [
                'id' => 191167,
                'email' => 'john@example.com',
                'phone' => '555-625-1199',
            ],
            'data_request' => ['id' => 9999],
        ], 'customers/data_request', webhookId: 'dr-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertOk();

        $request = ShopifyComplianceRequest::query()->first();
        $this->assertNotNull($request);
        $this->assertSame(ShopifyComplianceTopic::CustomersDataRequest, $request->topic);
        $this->assertSame(ShopifyComplianceStatus::Completed, $request->status);
        $this->assertSame($store->id, $request->store_id);
        $this->assertSame($store->workspace_id, $request->workspace_id);
        $this->assertTrue($request->hasInventoryArtifact());
        $this->assertSame(1, $request->inventory_summary['matched_contact_count'] ?? null);
        $this->assertNull($request->correlation_path);
        $this->assertDatabaseHas('shopify_webhook_deliveries', [
            'webhook_id' => 'dr-1',
            'topic' => 'customers/data_request',
        ]);
        $this->assertNotNull(
            ShopifyWebhookDelivery::query()->where('webhook_id', 'dr-1')->value('processed_at'),
        );
        // Delivery processed_at is acceptance, not a substitute for request completion — both set.
        $this->assertNotNull($request->completed_at);
        $this->assertFalse(
            str_contains(json_encode($request->inventory_summary), 'john@example.com'),
        );
    }

    public function test_duplicate_webhook_id_does_not_duplicate_request(): void
    {
        Storage::fake('local');
        $this->connectedStore();

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 2, 'email' => 'x@example.com'],
            'data_request' => ['id' => 42],
        ], 'customers/data_request', webhookId: 'dup-compliance');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();
        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(1, ShopifyComplianceRequest::query()->count());
        $this->assertSame(1, ShopifyWebhookDelivery::query()->where('webhook_id', 'dup-compliance')->count());
    }

    public function test_customers_redact_awaits_policy_without_deleting_contacts_or_consents(): void
    {
        Storage::fake('local');
        $store = $this->connectedStore();
        $contact = Contact::factory()->create([
            'store_id' => $store->id,
            'email' => 'keep@example.com',
        ]);
        $consentCount = ContactConsent::query()->where('contact_id', $contact->id)->count();

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 9, 'email' => 'keep@example.com'],
            'orders_to_redact' => [],
        ], 'customers/redact', webhookId: 'redact-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $request = ShopifyComplianceRequest::query()->first();
        $this->assertSame(ShopifyComplianceStatus::AwaitingPolicy, $request->status);
        $this->assertSame('pending_policy', $request->legal_checklist['linked_contact_erase'] ?? null);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'email' => 'keep@example.com']);
        $this->assertSame(
            $consentCount,
            ContactConsent::query()->where('contact_id', $contact->id)->count(),
        );
        $this->assertSame(0, $request->inventory_summary['contacts_erased'] ?? -1);
    }

    public function test_shop_redact_clears_credentials_and_completes_in_app_scope(): void
    {
        $store = $this->connectedStore();

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 954889,
            'shop_domain' => 'compliance-shop.myshopify.com',
        ], 'shop/redact', webhookId: 'shop-redact-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $request = ShopifyComplianceRequest::query()->first();
        $this->assertSame(ShopifyComplianceStatus::Completed, $request->status);
        $this->assertFalse($request->inventory_summary['external_backups_completed'] ?? true);
        $this->assertSame('ops_responsibility', $request->legal_checklist['external_backups'] ?? null);

        $fresh = $store->fresh();
        $this->assertNull($fresh->shopify_access_token);
        $this->assertSame(StoreConnectionStatus::Disconnected, $fresh->connection_status);
    }

    public function test_unknown_topic_does_not_create_compliance_request(): void
    {
        $this->connectedStore();
        [$body, $headers] = $this->signedWebhook(['id' => 1], 'products/create', webhookId: 'unknown-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $this->assertSame(0, ShopifyComplianceRequest::query()->count());
        $this->assertDatabaseHas('shopify_webhook_deliveries', [
            'webhook_id' => 'unknown-1',
            'topic' => 'products/create',
        ]);
    }

    public function test_queue_dispatch_path_records_received_event(): void
    {
        Queue::fake();
        Storage::fake('local');
        $this->connectedStore();

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 3, 'email' => 'q@example.com'],
            'data_request' => ['id' => 7],
        ], 'customers/data_request', webhookId: 'queued-1');

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)->assertOk();

        $request = ShopifyComplianceRequest::query()->first();
        $this->assertSame(ShopifyComplianceStatus::Received, $request->status);
        Queue::assertPushed(ProcessShopifyComplianceRequestJob::class);
        $this->assertDatabaseHas('shopify_compliance_request_events', [
            'compliance_request_id' => $request->id,
            'event_type' => ShopifyComplianceRequestEventType::Received->value,
        ]);
    }

    public function test_malformed_json_is_rejected(): void
    {
        $this->connectedStore();
        $rawBody = '{not-json';
        $hmac = base64_encode(hash_hmac(
            'sha256',
            $rawBody,
            (string) config('services.shopify.app_secret'),
            true,
        ));
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
            'HTTP_X_SHOPIFY_TOPIC' => 'customers/data_request',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'compliance-shop.myshopify.com',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'bad-json-1',
        ];

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $rawBody)
            ->assertStatus(400);
        $this->assertSame(0, ShopifyComplianceRequest::query()->count());
    }

    public function test_json_content_type_with_charset_is_accepted(): void
    {
        Storage::fake('local');
        $this->connectedStore();

        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 4, 'email' => 'ct@example.com'],
            'data_request' => ['id' => 88],
        ], 'customers/data_request', webhookId: 'ct-charset-1', headerOverrides: [
            'CONTENT_TYPE' => 'application/json; charset=utf-8',
        ]);

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertOk();
        $this->assertSame(1, ShopifyComplianceRequest::query()->count());
    }

    public function test_non_json_content_type_is_rejected_after_valid_hmac(): void
    {
        $this->connectedStore();
        [$body, $headers] = $this->signedWebhook([
            'shop_id' => 1,
            'shop_domain' => 'compliance-shop.myshopify.com',
            'customer' => ['id' => 5, 'email' => 'badct@example.com'],
            'data_request' => ['id' => 89],
        ], 'customers/data_request', webhookId: 'ct-bad-1', headerOverrides: [
            'CONTENT_TYPE' => 'text/plain',
        ]);

        $this->call('POST', '/api/v1/shopify/webhooks', [], [], [], $headers, $body)
            ->assertStatus(400);
        $this->assertSame(0, ShopifyComplianceRequest::query()->count());
    }
}
