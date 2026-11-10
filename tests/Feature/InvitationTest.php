<?php

namespace Tests\Feature;

use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesWorkspaces;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use CreatesWorkspaces, RefreshDatabase;

    public function test_admin_can_invite_and_only_hashed_token_is_stored(): void
    {
        Notification::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();

        $response = $this->spa()->actingAs($admin)->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'New.Person@Example.com', 'role' => 'marketer',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'new.person@example.com')
            ->assertJsonPath('data.role', 'marketer')
            ->assertJsonMissingPath('data.token')
            ->assertJsonMissingPath('data.token_hash');

        $invitation = WorkspaceInvitation::findOrFail($response->json('data.id'));
        $this->assertSame(64, strlen($invitation->token_hash));

        $plainToken = null;
        Notification::assertSentTo(
            new AnonymousNotifiable,
            WorkspaceInvitationNotification::class,
            function ($notification, $channels, $notifiable) use (&$plainToken, $invitation) {
                $url = $notification->toMail($notifiable)->actionUrl;
                parse_str(parse_url($url, PHP_URL_QUERY), $query);
                $plainToken = $query['token'];

                return $notifiable->routes['mail'] === 'new.person@example.com'
                    && $notification->invitation->is($invitation);
            }
        );

        $this->assertNotNull($plainToken);
        $this->assertNotSame($plainToken, $invitation->token_hash);
        $this->assertSame(WorkspaceInvitation::hashToken($plainToken), $invitation->token_hash);
        $this->assertDatabaseMissing('workspace_invitations', ['token_hash' => $plainToken]);
    }

    public function test_reinviting_replaces_pending_invitation(): void
    {
        Notification::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();
        $url = "/api/v1/workspaces/{$workspace->id}/invitations";

        $this->spa()->actingAs($admin)->postJson($url, ['email' => 'a@example.com', 'role' => 'marketer'])->assertCreated();
        $hash = WorkspaceInvitation::firstOrFail()->token_hash;
        $this->spa()->actingAs($admin)->postJson($url, ['email' => 'a@example.com', 'role' => 'admin'])->assertCreated();

        $this->assertDatabaseCount('workspace_invitations', 1);
        $this->assertNotSame($hash, WorkspaceInvitation::firstOrFail()->token_hash);
    }

    public function test_marketer_cannot_invite(): void
    {
        Notification::fake();
        [, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);

        $this->spa()->actingAs($marketer)->postJson("/api/v1/workspaces/{$workspace->id}/invitations", [
            'email' => 'a@example.com', 'role' => 'admin',
        ])->assertForbidden();

        $this->assertDatabaseCount('workspace_invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_cannot_invite_existing_member_or_invalid_role(): void
    {
        Notification::fake();
        [$admin, $workspace] = $this->adminWithWorkspace();
        $marketer = $this->marketerIn($workspace);
        $url = "/api/v1/workspaces/{$workspace->id}/invitations";

        $this->spa()->actingAs($admin)->postJson($url, ['email' => $marketer->email, 'role' => 'marketer'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->spa()->actingAs($admin)->postJson($url, ['email' => 'x@example.com', 'role' => 'owner'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
    }
}
