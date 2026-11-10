<?php

namespace App\Actions;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\UserWorkspaceContext;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateWorkspace
{
    /**
     * Creates the workspace, the creator's Admin membership and their
     * (empty) workspace context. Joins an outer transaction if there is one.
     */
    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name) {
            $workspace = Workspace::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'owner_id' => $owner->getKey(),
            ]);

            $workspace->members()->create([
                'user_id' => $owner->getKey(),
                'role' => WorkspaceRole::Admin,
            ]);

            UserWorkspaceContext::create([
                'user_id' => $owner->getKey(),
                'workspace_id' => $workspace->getKey(),
                'active_store_id' => null,
            ]);

            return $workspace;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';

        do {
            $slug = Str::limit($base, 40, '').'-'.Str::lower(Str::random(6));
        } while (Workspace::withTrashed()->where('slug', $slug)->exists());

        return $slug;
    }
}
