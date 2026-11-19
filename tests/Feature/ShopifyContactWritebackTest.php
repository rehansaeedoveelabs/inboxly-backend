<?php

namespace Tests\Feature;

use App\Enums\ShopifyCustomerIdentityLinkSource;
use App\Enums\ShopifyCustomerIdentityStatus;
use App\Jobs\PushContactToShopifyJob;
use App\Models\ShopifyCustomerIdentity;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesAudience;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyContactWritebackTest extends TestCase
{
    use CreatesAudience, CreatesWorkspaces, RefreshDatabase;

    public function test_contact_update_dispatches_shopify_writeback_job(): void
    {
        Queue::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = $this->storeIn($workspace);
        $contact = $this->contactIn($store, ['email' => 'jane@example.com', 'first_name' => 'Jane']);

        $this->spa()->actingAs($admin)
            ->putJson("/api/v1/contacts/{$contact->id}", ['first_name' => 'Janet'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Janet');

        Queue::assertPushed(PushContactToShopifyJob::class, fn (PushContactToShopifyJob $job) => $job->contactId === $contact->id);
    }

    public function test_push_updates_shopify_customer_when_scope_and_identity_exist(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('writeback-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'shopify_scopes' => ['read_customers', 'write_customers'],
        ]);
        $contact = $this->contactIn($store, [
            'email' => 'writeback@example.com',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'phone' => '+15550123456',
        ]);

        $identity = ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/9001',
            'status' => ShopifyCustomerIdentityStatus::Tracked,
        ]);

        Http::fake([
            'https://writeback-shop.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'customerUpdate' => [
                        'customer' => [
                            'id' => 'gid://shopify/Customer/9001',
                            'firstName' => 'Ada',
                            'lastName' => 'Lovelace',
                            'defaultEmailAddress' => ['emailAddress' => 'writeback@example.com'],
                            'defaultPhoneNumber' => ['phoneNumber' => '+15550123456'],
                        ],
                        'userErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        (new PushContactToShopifyJob($contact->id))->handle(app(\App\Actions\Shopify\PushContactToShopify::class));

        $identity->refresh();
        $this->assertSame(ShopifyCustomerIdentityLinkSource::InboxlyWriteback, $identity->link_source);
        $this->assertNotNull($identity->last_synced_at);
        Http::assertSentCount(1);
    }

    public function test_push_skips_without_write_customers_scope(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('readonly-shop.myshopify.com')->create([
            'workspace_id' => $workspace->id,
            'shopify_scopes' => ['read_customers'],
        ]);
        $contact = $this->contactIn($store, ['email' => 'skip@example.com']);
        ShopifyCustomerIdentity::factory()->linkedTo($contact)->create([
            'shopify_customer_gid' => 'gid://shopify/Customer/9002',
        ]);

        Http::fake();

        (new PushContactToShopifyJob($contact->id))->handle(app(\App\Actions\Shopify\PushContactToShopify::class));

        Http::assertNothingSent();
    }
}
