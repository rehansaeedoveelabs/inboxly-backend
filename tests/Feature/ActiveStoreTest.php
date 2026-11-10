<?php

namespace Tests\Feature;

use App\Actions\SetActiveStore;
use App\Enums\WorkspaceRole;
use App\Models\Store;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class ActiveStoreTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_admin_can_activate_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$store->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.workspace_id', $workspace->id)
            ->assertJsonPath('data.active_store_id', $store->id)
            ->assertJsonPath('data.active_store.id', $store->id);

        $this->assertDatabaseHas('user_workspace_contexts', [
            'user_id' => $admin->id, 'workspace_id' => $workspace->id, 'active_store_id' => $store->id,
        ]);
        $this->assertDatabaseCount('user_workspace_contexts', 1);

        $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertJsonPath('data.active_store_id', $store->id);
    }

    public function test_marketer_can_activate_accessible_store_and_switch(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $one = Store::factory()->create(['workspace_id' => $workspace->id]);
        $two = Store::factory()->create(['workspace_id' => $workspace->id]);
        $marketer = $this->marketerIn($workspace);

        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$one->id}/activate")->assertOk();
        $this->spa()->actingAs($marketer)->postJson("/api/v1/stores/{$two->id}/activate")
            ->assertOk()->assertJsonPath('data.active_store_id', $two->id);

        $this->assertDatabaseHas('user_workspace_contexts', [
            'user_id' => $marketer->id, 'workspace_id' => $workspace->id, 'active_store_id' => $two->id,
        ]);
    }

    public function test_active_store_is_per_user_and_per_workspace(): void
    {
        [$admin, $workspaceA] = $this->adminWithWorkspace('A');
        $storeA = Store::factory()->create(['workspace_id' => $workspaceA->id]);
        $marketer = $this->marketerIn($workspaceA);

        [, $workspaceB] = $this->adminWithWorkspace('B');
        $this->addMember($workspaceB, $admin, WorkspaceRole::Marketer);
        $storeB = Store::factory()->create(['workspace_id' => $workspaceB->id]);

        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$storeA->id}/activate")->assertOk();
        $this->spa()->actingAs($admin)->postJson("/api/v1/stores/{$storeB->id}/activate")->assertOk();

        $this->assertDatabaseHas('user_workspace_contexts', ['user_id' => $admin->id, 'workspace_id' => $workspaceA->id, 'active_store_id' => $storeA->id]);
        $this->assertDatabaseHas('user_workspace_contexts', ['user_id' => $admin->id, 'workspace_id' => $workspaceB->id, 'active_store_id' => $storeB->id]);
        $this->assertDatabaseMissing('user_workspace_contexts', ['user_id' => $marketer->id, 'workspace_id' => $workspaceA->id]);
    }

    public function test_action_rejects_store_from_another_workspace(): void
    {
        [$admin, $workspaceA] = $this->adminWithWorkspace('A');
        [, $workspaceB] = $this->adminWithWorkspace('B');
        $foreignStore = Store::factory()->create(['workspace_id' => $workspaceB->id]);

        $this->expectException(ValidationException::class);

        try {
            app(SetActiveStore::class)->handle($admin, $workspaceA, $foreignStore);
        } finally {
            $this->assertDatabaseMissing('user_workspace_contexts', ['active_store_id' => $foreignStore->id]);
        }
    }

    public function test_action_rejects_non_member(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $this->expectException(AuthorizationException::class);

        app(SetActiveStore::class)->handle(User::factory()->create(), $workspace, $store);
    }

    public function test_action_can_clear_active_store(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);
        $action = app(SetActiveStore::class);

        $action->handle($admin, $workspace, $store);
        $context = $action->handle($admin, $workspace, null);

        $this->assertNull($context->active_store_id);
    }

    public function test_unauthenticated_cannot_activate(): void
    {
        [, $workspace] = $this->adminWithWorkspace();
        $store = Store::factory()->create(['workspace_id' => $workspace->id]);

        $this->spa()->postJson("/api/v1/stores/{$store->id}/activate")->assertUnauthorized();
    }
}
