<?php

namespace App\Http\Resources;

use App\Models\UserWorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserWorkspaceContext */
class ContextResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'workspace_id' => $this->workspace_id,
            'active_store_id' => $this->active_store_id,
            'active_store' => new StoreResource($this->whenLoaded('activeStore')),
            'updated_at' => $this->updated_at,
        ];
    }
}
