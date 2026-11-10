<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InviteMember
{
    public const TTL_DAYS = 7;

    public function handle(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role): WorkspaceInvitation
    {
        $email = Str::lower($email);

        $alreadyMember = $workspace->members()
            ->whereHas('user', fn ($q) => $q->where('email', $email))
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages(['email' => ['This person is already a member of the workspace.']]);
        }

        // Only the SHA-256 hash is stored; the plain token only exists in the email.
        $plainToken = Str::random(64);

        $invitation = $workspace->invitations()->updateOrCreate(
            ['email' => $email, 'accepted_at' => null],
            [
                'invited_by' => $inviter->getKey(),
                'role' => $role,
                'token_hash' => WorkspaceInvitation::hashToken($plainToken),
                'expires_at' => now()->addDays(self::TTL_DAYS),
            ],
        );

        Notification::route('mail', $email)
            ->notify(new WorkspaceInvitationNotification($invitation->load(['workspace', 'inviter']), $plainToken));

        return $invitation;
    }
}
