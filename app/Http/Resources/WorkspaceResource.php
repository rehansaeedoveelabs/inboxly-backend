<?php

namespace App\Http\Resources;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workspace */
class WorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = isset($this->pivot) ? WorkspaceRole::from($this->pivot->role) : $this->roleFor($user);
        $isAdmin = $role === WorkspaceRole::Admin;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'role' => $role?->value,
            'active_store_id' => $user->activeStoreIdFor($this->resource),
            'permissions' => [
                'manage_members' => $isAdmin,
                'create_stores' => $isAdmin,
                'manage_settings' => $isAdmin,
            ],
            // Settings are admin-only; marketers get a limited view without them.
            'settings' => $this->when($isAdmin, $this->settings),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
