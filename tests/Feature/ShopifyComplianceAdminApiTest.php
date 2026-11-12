<?php

namespace Tests\Feature;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Enums\ShopifyComplianceStatus;
use App\Enums\ShopifyComplianceTopic;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyComplianceRequestEvent;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ShopifyComplianceAdminApiTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    /**
     * @return array{0: \App\Models\User, 1: Store}
     */
    private function adminStore(): array
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->withShopifyCredentials('admin-compliance.myshopify.com')->create([
            'workspace_id' => $workspace->id,
        ]);

        return [$admin, $store];
    }

    public function test_admin_can_list_and_view_store_requests(): void
    {
        [$admin, $store] = $this->adminStore();
        $request = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'shop_domain' => 'admin-compliance.myshopify.com',
            'topic' => ShopifyComplianceTopic::CustomersDataRequest,
            'status' => ShopifyComplianceStatus::Completed,
            'webhook_id' => 'admin-list-1',
            'shopify_data_request_id' => '55',
            'inventory_summary' => ['matched_contact_count' => 1],
            'inventory_disk' => 'local',
            'inventory_path' => 'compliance/x/inventory.json',
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/compliance-requests")
            ->assertOk()
            ->assertJsonPath('data.0.id', $request->id)
            ->assertJsonMissingPath('data.0.inventory_path')
            ->assertJsonMissingPath('data.0.correlation_path')
            ->assertJsonPath('data.0.inventory_artifact_ready', true);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$request->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.inventory_path');
    }

    public function test_marketer_cannot_list_compliance_requests(): void
    {
        [, $store] = $this->adminStore();
        $marketer = $this->marketerIn($store->workspace);

        $this->spa()->actingAs($marketer)
            ->getJson("/api/v1/stores/{$store->id}/shopify/compliance-requests")
            ->assertForbidden();
    }

    public function test_marketer_cannot_retry_compliance_request(): void
    {
        [, $store] = $this->adminStore();
        $marketer = $this->marketerIn($store->workspace);
        $failed = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Failed,
            'webhook_id' => 'marketer-retry-1',
            'shopify_data_request_id' => null,
        ]);

        $this->spa()->actingAs($marketer)
            ->postJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$failed->id}/retry")
            ->assertForbidden();

        $this->assertSame(ShopifyComplianceStatus::Failed, $failed->fresh()->status);
    }

    public function test_cross_store_request_is_not_visible(): void
    {
        [$admin, $store] = $this->adminStore();
        [, $otherWorkspace] = $this->adminWithWorkspace('Other');
        $otherStore = Store::factory()->create(['workspace_id' => $otherWorkspace->id]);
        $foreign = ShopifyComplianceRequest::factory()->create([
            'store_id' => $otherStore->id,
            'workspace_id' => $otherWorkspace->id,
            'webhook_id' => 'foreign-1',
            'shopify_data_request_id' => null,
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_admin_can_retry_failed_request_only(): void
    {
        Queue::fake();
        [$admin, $store] = $this->adminStore();
        $failed = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Failed,
            'webhook_id' => 'retry-failed-1',
            'shopify_data_request_id' => null,
            'processing_checkpoint' => ['resolve_store' => ['done' => true]],
            'error_code' => 'RuntimeException',
        ]);
        $awaiting = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::AwaitingPolicy,
            'webhook_id' => 'retry-await-1',
            'shopify_data_request_id' => null,
        ]);
        $completed = ShopifyComplianceRequest::factory()->create([
            'store_id' => $store->id,
            'workspace_id' => $store->workspace_id,
            'status' => ShopifyComplianceStatus::Completed,
            'webhook_id' => 'retry-done-1',
            'shopify_data_request_id' => null,
        ]);

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$failed->id}/retry")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'received');

        $this->assertDatabaseHas('shopify_compliance_request_events', [
            'compliance_request_id' => $failed->id,
            'event_type' => ShopifyComplianceRequestEventType::RetryRequested->value,
            'actor_user_id' => $admin->id,
        ]);
        $this->assertSame(
            ['resolve_store' => ['done' => true]],
            $failed->fresh()->processing_checkpoint,
        );

        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$awaiting->id}/retry")
            ->assertStatus(409);
        $this->spa()->actingAs($admin)
            ->postJson("/api/v1/stores/{$store->id}/shopify/compliance-requests/{$completed->id}/retry")
            ->assertStatus(409);
    }

    public function test_null_store_requests_are_not_listed_under_unrelated_store(): void
    {
        [$admin, $store] = $this->adminStore();
        ShopifyComplianceRequest::factory()->withoutStore()->create([
            'workspace_id' => null,
            'shop_domain' => 'orphan.myshopify.com',
            'webhook_id' => 'orphan-1',
            'shopify_data_request_id' => null,
            'topic' => ShopifyComplianceTopic::ShopRedact,
        ]);

        $this->spa()->actingAs($admin)
            ->getJson("/api/v1/stores/{$store->id}/shopify/compliance-requests")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
