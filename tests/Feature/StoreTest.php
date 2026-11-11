<?php

namespace Tests\Feature;

use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class StoreTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_admin_can_create_and_list_stores(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();

        $this->spa()->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/stores", [
            'name' => 'Main Store',
            'domain' => 'https://Main-Store.myshopify.com/',
            'timezone' => 'Asia/Karachi',
            'currency' => 'pkr',
            'metadata' => ['source' => 'manual'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Main Store')
            ->assertJsonPath('data.domain', 'main-store.myshopify.com')
            ->assertJsonPath('data.platform', 'shopify')
            ->assertJsonPath('data.connection_status', 'pending')
            ->assertJsonPath('data.is_connected', false)
            ->assertJsonPath('data.currency', 'PKR')
            ->assertJsonPath('data.metadata.source', 'manual');

        $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}/stores")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Main Store');
    }

    public function test_client_cannot_self_declare_store_as_connected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();

        $this->spa()->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/stores", [
            'name' => 'Sneaky', 'domain' => 'sneaky.myshopify.com', 'connection_status' => 'connected',
        ])->assertCreated()
            ->assertJsonPath('data.connection_status', 'pending')
            ->assertJsonPath('data.is_connected', false);
    }

    public function test_demo_store_never_looks_like_connected_shopify(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();

        $this->spa()->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/stores", [
            'name' => 'Demo Store', 'is_demo' => true, 'connection_status' => 'connected',
        ])->assertCreated()
            ->assertJsonPath('data.is_demo', true)
            ->assertJsonPath('data.connection_status', 'demo')
            ->assertJsonPath('data.is_connected', false)
            ->assertJsonPath('data.domain', null);

        // Model-level invariant, independent of the HTTP layer.
        $store = Store::factory()->create(['workspace_id' => $workspace->id, 'is_demo' => true, 'connection_status' => 'connected']);
        $this->assertSame('demo', $store->fresh()->connection_status->value);
        $this->assertFalse($store->fresh()->isConnected());
    }

    public function test_store_validation(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $url = "/api/v1/workspaces/{$workspace->id}/stores";

        $this->spa()->actingAs($admin)->postJson($url, [])
            ->assertUnprocessable()->assertJsonValidationErrors(['name', 'domain']);

        $this->spa()->actingAs($admin)->postJson($url, [
            'name' => 'X', 'domain' => 'not a domain', 'platform' => 'magento', 'timezone' => 'Mars/Base', 'currency' => 'dollars',
        ])->assertUnprocessable()->assertJsonValidationErrors(['domain', 'platform', 'timezone', 'currency']);

        $this->spa()->actingAs($admin)->postJson($url, [
            'name' => 'External', 'domain' => 'evil.example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors(['domain']);

        $this->spa()->actingAs($admin)->postJson($url, [
            'name' => 'Port', 'domain' => 'acme.myshopify.com:443',
        ])->assertUnprocessable()->assertJsonValidationErrors(['domain']);

        $this->spa()->actingAs($admin)->postJson($url, [
            'name' => 'Query', 'domain' => 'acme.myshopify.com?x=1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['domain']);

        $this->spa()->actingAs($admin)->postJson($url, ['name' => 'A', 'domain' => 'dup.myshopify.com'])->assertCreated();
        $this->spa()->actingAs($admin)->postJson($url, ['name' => 'B', 'domain' => 'dup.myshopify.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('domain');
    }

    public function test_shopify_domain_is_normalized_and_external_hosts_rejected(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $url = "/api/v1/workspaces/{$workspace->id}/stores";

        $this->spa()->actingAs($admin)->postJson($url, [
            'name' => 'Normalized',
            'domain' => 'https://Cool-Shop.myshopify.com/admin',
        ])->assertCreated()->assertJsonPath('data.domain', 'cool-shop.myshopify.com')
            ->assertJsonPath('data.connection_status', 'pending');

        foreach (['google.com', 'cool-shop.myshopify.com.evil.com', 'http://localhost', ''] as $bad) {
            $this->spa()->actingAs($admin)->postJson($url, [
                'name' => 'Bad',
                'domain' => $bad,
            ])->assertUnprocessable()->assertJsonValidationErrors(['domain']);
        }
    }

    public function test_same_domain_allowed_in_different_workspaces(): void
    {
        [$adminA, $wsA] = $this->adminWithWorkspace('A');
        [$adminB, $wsB] = $this->adminWithWorkspace('B');

        $this->spa()->actingAs($adminA)->postJson("/api/v1/workspaces/{$wsA->id}/stores", ['name' => 'S', 'domain' => 'same.myshopify.com'])->assertCreated();
        $this->spa()->actingAs($adminB)->postJson("/api/v1/workspaces/{$wsB->id}/stores", ['name' => 'S', 'domain' => 'same.myshopify.com'])->assertCreated();
    }

    public function test_marketer_can_list_but_cannot_create_stores(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        Store::factory()->create(['workspace_id' => $workspace->id]);
        $marketer = $this->marketerIn($workspace);

        $this->spa()->actingAs($marketer)->getJson("/api/v1/workspaces/{$workspace->id}/stores")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->spa()->actingAs($marketer)->postJson("/api/v1/workspaces/{$workspace->id}/stores", [
            'name' => 'Nope', 'domain' => 'nope.myshopify.com',
        ])->assertForbidden();

        $this->assertDatabaseCount('stores', 1);
    }

    public function test_cross_workspace_store_access_is_denied(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        $storeA = Store::factory()->create(['workspace_id' => $workspaceA->id]);
        [$adminB, $workspaceB] = $this->adminWithWorkspace('B');

        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceA->id}/stores")->assertForbidden();
        $this->spa()->actingAs($adminB)->postJson("/api/v1/workspaces/{$workspaceA->id}/stores", [
            'name' => 'Intruder', 'domain' => 'intruder.myshopify.com',
        ])->assertForbidden();
        $this->spa()->actingAs($adminB)->postJson("/api/v1/stores/{$storeA->id}/activate")->assertForbidden();

        // Own workspace listing does not leak other workspace's stores.
        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceB->id}/stores")
            ->assertOk()->assertJsonCount(0, 'data');

        $this->assertDatabaseMissing('user_workspace_contexts', ['user_id' => $adminB->id, 'active_store_id' => $storeA->id]);
    }

    public function test_soft_deleted_stores_are_hidden(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);
        $store->delete();

        $this->assertSoftDeleted($store);
        $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}/stores")->assertJsonCount(0, 'data');
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/activate")->assertNotFound();
    }
}
