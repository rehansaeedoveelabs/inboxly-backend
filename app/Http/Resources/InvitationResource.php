<?php

namespace App\Http\Resources;

use App\Models\WorkspaceInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkspaceInvitation */
class InvitationResource extends JsonResource
{
    /** The token (plain or hashed) is never exposed. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'email' => $this->email,
            'role' => $this->role->value,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}
