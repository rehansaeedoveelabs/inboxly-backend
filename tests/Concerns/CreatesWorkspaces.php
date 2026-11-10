<?php

namespace Tests\Concerns;

use App\Actions\CreateWorkspace;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

trait CreatesWorkspaces
{
    /** @return array{0: User, 1: Workspace} */
    protected function adminWithWorkspace(string $name = 'Acme'): array
    {
        $user = User::factory()->create();

        return [$user, app(CreateWorkspace::class)->handle($user, $name)];
    }

    protected function addMember(Workspace $workspace, User $user, WorkspaceRole $role): User
    {
        $workspace->members()->create(['user_id' => $user->getKey(), 'role' => $role]);

        return $user;
    }

    protected function marketerIn(Workspace $workspace): User
    {
        return $this->addMember($workspace, User::factory()->create(), WorkspaceRole::Marketer);
    }
}
