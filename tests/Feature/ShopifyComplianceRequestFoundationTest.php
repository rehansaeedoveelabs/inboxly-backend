<?php

namespace Tests\Feature;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyComplianceRequestEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ShopifyComplianceRequestFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_uses_ulid_primary_keys(): void
    {
        $request = ShopifyComplianceRequest::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $request->id);
        $this->assertTrue($request->wasRecentlyCreated);
    }

    public function test_all_lifecycle_statuses_persist(): void
    {
        foreach (ShopifyComplianceStatus::cases() as $status) {
            $request = ShopifyComplianceRequest::factory()->status($status)->create([
                'webhook_id' => 'status-'.$status->value,
                'shopify_data_request_id' => null,
            ]);

            $this->assertSame($status, $request->fresh()->status);
        }

        $this->assertSame(
            count(ShopifyComplianceStatus::cases()),
            ShopifyComplianceRequest::query()->count(),
        );
        $this->assertSame(
            [
                'received',
                'processing',
                'partially_processed',
                'awaiting_policy',
                'failed',
                'completed',
            ],
            array_column(ShopifyComplianceStatus::cases(), 'value'),
        );
    }

    public function test_topics_cast_and_json_fields_round_trip(): void
    {
        $request = ShopifyComplianceRequest::factory()->create([
            'topic' => ShopifyComplianceTopic::CustomersRedact,
            'shopify_data_request_id' => null,
            'processing_checkpoint' => ['resolve_store' => 'done'],
            'legal_checklist' => ['linked_contact_erase' => 'pending_policy'],
            'inventory_summary' => ['contacts_matched' => 2],
            'inventory_disk' => 'local',
            'inventory_path' => 'compliance/example/inventory.json',
            'payload_sha256' => hash('sha256', 'raw-body'),
        ]);

        $fresh = $request->fresh();

        $this->assertSame(ShopifyComplianceTopic::CustomersRedact, $fresh->topic);
        $this->assertSame(['resolve_store' => 'done'], $fresh->processing_checkpoint);
        $this->assertSame(['linked_contact_erase' => 'pending_policy'], $fresh->legal_checklist);
        $this->assertSame(['contacts_matched' => 2], $fresh->inventory_summary);
        $this->assertSame('local', $fresh->inventory_disk);
        $this->assertSame('compliance/example/inventory.json', $fresh->inventory_path);
        $this->assertSame(64, strlen((string) $fresh->payload_sha256));
    }

    public function test_webhook_id_is_unique(): void
    {
        ShopifyComplianceRequest::factory()->create([
            'webhook_id' => 'delivery-unique-1',
            'shopify_data_request_id' => '1001',
        ]);

        $this->expectException(QueryException::class);

        ShopifyComplianceRequest::factory()->create([
            'webhook_id' => 'delivery-unique-1',
            'shopify_data_request_id' => '1002',
        ]);
    }

    public function test_data_request_id_is_unique_per_shop_and_topic(): void
    {
        ShopifyComplianceRequest::factory()->create([
            'shop_domain' => 'dup-shop.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'webhook_id' => 'delivery-a',
            'shopify_data_request_id' => '9999',
        ]);

        $this->expectException(QueryException::class);

        ShopifyComplianceRequest::factory()->create([
            'shop_domain' => 'dup-shop.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'webhook_id' => 'delivery-b',
            'shopify_data_request_id' => '9999',
        ]);
    }

    public function test_null_data_request_ids_do_not_collide_across_redact_rows(): void
    {
        ShopifyComplianceRequest::factory()->customersRedact()->create([
            'shop_domain' => 'null-dr.myshopify.com',
            'webhook_id' => 'redact-1',
        ]);

        $second = ShopifyComplianceRequest::factory()->customersRedact()->create([
            'shop_domain' => 'null-dr.myshopify.com',
            'webhook_id' => 'redact-2',
        ]);

        $this->assertNull($second->shopify_data_request_id);
        $this->assertSame(2, ShopifyComplianceRequest::query()
            ->where('shop_domain', 'null-dr.myshopify.com')
            ->count());
    }

    public function test_store_id_nulls_when_store_is_hard_deleted(): void
    {
        $store = Store::factory()->create();
        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'shopify_data_request_id' => null,
            'webhook_id' => 'store-null-on-delete',
        ]);

        $store->forceDelete();

        $this->assertNull($request->fresh()->store_id);
    }

    public function test_request_may_be_created_without_store(): void
    {
        $request = ShopifyComplianceRequest::factory()->withoutStore()->create([
            'webhook_id' => 'no-store-1',
            'shopify_data_request_id' => null,
        ]);

        $this->assertNull($request->fresh()->store_id);
    }

    public function test_events_use_ulids_and_nullable_actor(): void
    {
        $request = ShopifyComplianceRequest::factory()->create([
            'webhook_id' => 'event-ulid-1',
            'shopify_data_request_id' => null,
        ]);

        $systemEvent = ShopifyComplianceRequestEvent::factory()->create([
            'compliance_request_id' => $request->id,
            'actor_user_id' => null,
            'event_type' => ShopifyComplianceRequestEventType::Received,
        ]);

        $admin = User::factory()->create();
        $adminEvent = ShopifyComplianceRequestEvent::factory()->retryRequested()->create([
            'compliance_request_id' => $request->id,
            'actor_user_id' => $admin->id,
        ]);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $systemEvent->id);
        $this->assertNull($systemEvent->fresh()->actor_user_id);
        $this->assertSame($admin->id, $adminEvent->fresh()->actor_user_id);
        $this->assertSame(ShopifyComplianceRequestEventType::RetryRequested, $adminEvent->fresh()->event_type);
        $this->assertSame(2, $request->events()->count());
    }

    public function test_actor_user_id_nulls_when_user_is_deleted(): void
    {
        $admin = User::factory()->create();
        $event = ShopifyComplianceRequestEvent::factory()->create([
            'actor_user_id' => $admin->id,
            'event_type' => ShopifyComplianceRequestEventType::RetryRequested,
        ]);

        $admin->delete();

        $this->assertNull($event->fresh()->actor_user_id);
    }

    public function test_audit_events_reject_update_and_delete(): void
    {
        $event = ShopifyComplianceRequestEvent::factory()->create();

        try {
            $event->update(['meta' => ['tampered' => true]]);
            $this->fail('Expected LogicException on update.');
        } catch (LogicException $e) {
            $this->assertSame('Compliance request events are append-only.', $e->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Expected LogicException on delete.');
        } catch (LogicException $e) {
            $this->assertSame('Compliance request events are append-only.', $e->getMessage());
        }

        $this->assertDatabaseHas('shopify_compliance_request_events', [
            'id' => $event->id,
        ]);
    }

    public function test_schema_has_no_email_or_phone_hash_columns(): void
    {
        $columns = collect(\Illuminate\Support\Facades\Schema::getColumnListing('shopify_compliance_requests'));

        $this->assertFalse($columns->contains('customer_email_hash'));
        $this->assertFalse($columns->contains('customer_phone_hash'));
        $this->assertFalse($columns->contains('email'));
        $this->assertFalse($columns->contains('phone'));
        $this->assertFalse($columns->contains('payload'));
        $this->assertFalse($columns->contains('raw_body'));
    }

    public function test_same_data_request_id_allowed_for_different_shops(): void
    {
        ShopifyComplianceRequest::factory()->create([
            'shop_domain' => 'shop-a.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'webhook_id' => 'cross-shop-a',
            'shopify_data_request_id' => 'shared-999',
        ]);

        $other = ShopifyComplianceRequest::factory()->create([
            'shop_domain' => 'shop-b.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'webhook_id' => 'cross-shop-b',
            'shopify_data_request_id' => 'shared-999',
        ]);

        $this->assertSame('shared-999', $other->shopify_data_request_id);
        $this->assertSame(
            2,
            ShopifyComplianceRequest::query()->where('shopify_data_request_id', 'shared-999')->count(),
        );
    }

    public function test_parent_request_delete_is_restricted_while_audit_events_exist(): void
    {
        $request = ShopifyComplianceRequest::factory()->create([
            'webhook_id' => 'restrict-parent-1',
            'shopify_data_request_id' => null,
        ]);
        ShopifyComplianceRequestEvent::factory()->create([
            'compliance_request_id' => $request->id,
        ]);

        try {
            $request->delete();
            $this->fail('Expected QueryException when deleting a request that still has audit events.');
        } catch (QueryException $e) {
            $this->assertTrue(
                str_contains(mb_strtolower($e->getMessage()), 'foreign key')
                || str_contains(mb_strtolower($e->getMessage()), '1451')
                || ($e->errorInfo[0] ?? '') === '23000',
            );
        }

        $this->assertDatabaseHas('shopify_compliance_requests', ['id' => $request->id]);
        $this->assertSame(1, ShopifyComplianceRequestEvent::query()
            ->where('compliance_request_id', $request->id)
            ->count());
    }
}
