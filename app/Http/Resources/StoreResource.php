<?php

namespace App\Http\Resources;

use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Store */
class StoreResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'domain' => $this->domain,
            'platform' => $this->platform->value,
            'connection_status' => $this->connection_status->value,
            'is_demo' => $this->is_demo,
            // True only for a real, authorised connection. Demo stores are never "connected".
            'is_connected' => $this->isConnected(),
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
