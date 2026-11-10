<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_creating_workspace_makes_creator_admin_with_context(): void
    {
        $user = User::factory()->create();

        $response = $this->spa()->actingAs($user)->postJson('/api/v1/workspaces', ['name' => 'Brand Co'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Brand Co')
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.permissions.create_stores', true);

        $workspace = Workspace::findOrFail($response->json('data.id'));
        $this->assertSame(WorkspaceRole::Admin, $workspace->roleFor($user));
        $this->assertDatabaseHas('user_workspace_contexts', ['user_id' => $user->id, 'workspace_id' => $workspace->id]);
    }

    public function test_name_is_required(): void
    {
        $this->spa()->actingAs(User::factory()->create())->postJson('/api/v1/workspaces', [])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_user_can_belong_to_multiple_workspaces_with_different_roles(): void
    {
        [$user, $own] = $this->adminWithWorkspace('Own');
        [, $other] = $this->adminWithWorkspace('Other');
        $this->addMember($other, $user, WorkspaceRole::Marketer);
        $this->adminWithWorkspace('Unrelated');

        $response = $this->spa()->actingAs($user)->getJson('/api/v1/workspaces')->assertOk();

        $response->assertJsonCount(2, 'data');
        $roles = collect($response->json('data'))->pluck('role', 'name');
        $this->assertSame('admin', $roles['Own']);
        $this->assertSame('marketer', $roles['Other']);

        $this->spa()->actingAs($user)->getJson("/api/v1/workspaces/{$own->id}")->assertOk();
        $this->spa()->actingAs($user)->getJson("/api/v1/workspaces/{$other->id}")
            ->assertOk()->assertJsonPath('data.role', 'marketer');
    }

    public function test_admin_can_update_workspace(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();

        $this->spa()->actingAs($admin)->putJson("/api/v1/workspaces/{$workspace->id}", [
            'name' => 'Renamed', 'settings' => ['locale' => 'en'],
        ])->assertOk()
            ->assertJsonPath('data.name', 'Renamed')
            ->assertJsonPath('data.settings.locale', 'en');
    }

    public function test_marketer_can_view_but_not_update_and_sees_no_settings(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $workspace->update(['settings' => ['secret' => 'x']]);
        $marketer = $this->marketerIn($workspace);

        $this->spa()->actingAs($marketer)->getJson("/api/v1/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.permissions.manage_settings', false)
            ->assertJsonMissingPath('data.settings');

        $this->spa()->actingAs($marketer)->putJson("/api/v1/workspaces/{$workspace->id}", ['name' => 'Hacked'])
            ->assertForbidden();

        $this->assertSame($workspace->name, $workspace->fresh()->name);
    }

    public function test_members_list_visible_to_members_only(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);

        $response = $this->spa()->actingAs($marketer)->getJson("/api/v1/workspaces/{$workspace->id}/members")
            ->assertOk()->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing(['admin', 'marketer'], array_column($response->json('data'), 'role'));
        $response->assertJsonMissingPath('data.0.password');

        $outsider = User::factory()->create();
        $this->spa()->actingAs($outsider)->getJson("/api/v1/workspaces/{$workspace->id}/members")->assertForbidden();
    }

    public function test_cross_workspace_access_is_denied(): void
    {
        [, $workspaceA] = $this->adminWithWorkspace('A');
        [$adminB] = $this->adminWithWorkspace('B');

        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceA->id}")->assertForbidden();
        $this->spa()->actingAs($adminB)->putJson("/api/v1/workspaces/{$workspaceA->id}", ['name' => 'x'])->assertForbidden();
        $this->spa()->actingAs($adminB)->getJson("/api/v1/workspaces/{$workspaceA->id}/members")->assertForbidden();
        $this->spa()->actingAs($adminB)->postJson("/api/v1/workspaces/{$workspaceA->id}/invitations", [
            'email' => 'a@example.com', 'role' => 'marketer',
        ])->assertForbidden();
    }

    public function test_soft_deleted_workspace_is_not_accessible(): void
    {
        [$admin, $workspace] = $this->adminWithWorkspace();
        $workspace->delete();

        $this->assertSoftDeleted($workspace);
        $this->spa()->actingAs($admin)->getJson("/api/v1/workspaces/{$workspace->id}")->assertNotFound();
        $this->spa()->actingAs($admin)->getJson('/api/v1/workspaces')->assertJsonCount(0, 'data');
    }
}
