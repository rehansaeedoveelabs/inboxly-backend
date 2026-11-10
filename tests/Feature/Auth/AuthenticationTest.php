<?php

namespace Tests\Feature\Auth;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides);
    }

    public function test_user_can_register_with_default_workspace_admin_membership_and_context(): void
    {
        Notification::fake();

        $response = $this->spa()->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'jane@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.workspaces.0.role', 'admin')
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'jane@example.com');
        $this->assertTrue(strlen($user->id) === 26, 'User id should be a ULID');
        $this->assertAuthenticatedAs($user, 'web');

        $workspace = Workspace::firstOrFail();
        $this->assertSame($user->id, $workspace->owner_id);
        $this->assertSame(WorkspaceRole::Admin, $workspace->roleFor($user));
        $this->assertDatabaseHas('user_workspace_contexts', [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'active_store_id' => null,
        ]);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_accepts_custom_workspace_name(): void
    {
        $this->spa()->postJson('/api/v1/auth/register', $this->payload(['workspace_name' => 'Brand Co']))
            ->assertCreated();

        $this->assertSame('Brand Co', Workspace::firstOrFail()->name);
    }

    public function test_registration_is_atomic_when_workspace_creation_fails(): void
    {
        Workspace::creating(fn () => throw new \RuntimeException('boom'));

        $this->withoutExceptionHandling();

        try {
            $this->spa()->postJson('/api/v1/auth/register', $this->payload());
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('workspaces', 0);
        $this->assertDatabaseCount('user_workspace_contexts', 0);
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->spa()->postJson('/api/v1/auth/register', $this->payload(['password_confirmation' => 'nope']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_user_can_login_and_me_returns_workspaces(): void
    {
        [$user] = [User::factory()->create(['email' => 'jane@example.com'])];
        app(CreateWorkspace::class)->handle($user, 'Acme');

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'Jane@Example.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.workspaces.0.name', 'Acme');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_login_with_wrong_credentials_fails(): void
    {
        User::factory()->create(['email' => 'jane@example.com']);

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->spa()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }

        $this->spa()->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_user_can_logout(): void
    {
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email_verified', true);
    }

    public function test_unauthenticated_requests_are_denied(): void
    {
        $this->spa()->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/auth/logout')->assertUnauthorized();
        $this->spa()->putJson('/api/v1/auth/profile', ['name' => 'x'])->assertUnauthorized();
        $this->spa()->putJson('/api/v1/auth/password', [])->assertUnauthorized();
        $this->spa()->getJson('/api/v1/workspaces')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/workspaces', ['name' => 'x'])->assertUnauthorized();
        $this->spa()->getJson('/api/v1/workspaces/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertUnauthorized();
        $this->spa()->getJson('/api/v1/workspaces/01ARZ3NDEKTSV4RRFFQ69G5FAV/stores')->assertUnauthorized();
        $this->spa()->postJson('/api/v1/stores/01ARZ3NDEKTSV4RRFFQ69G5FAV/activate')->assertUnauthorized();
    }

    public function test_profile_can_be_updated_and_email_change_resets_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'old@example.com']);

        $this->spa()->actingAs($user)
            ->putJson('/api/v1/auth/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('data.email_verified', false);

        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_profile_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->putJson('/api/v1/auth/profile', ['email' => 'taken@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_password_can_be_changed_with_current_password(): void
    {
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertNoContent();

        $this->assertTrue(\Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_password_change_requires_correct_current_password(): void
    {
        $user = User::factory()->create();

        $this->spa()->actingAs($user)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
    }
}
