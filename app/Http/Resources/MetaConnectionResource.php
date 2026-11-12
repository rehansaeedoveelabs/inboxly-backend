<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MetaConnection */
class MetaConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'store_id' => $this->store_id,
            'meta_user_id' => $this->meta_user_id,
            'status' => $this->status?->value ?? $this->status,
            'scopes' => $this->scopes ?? [],
            'connected_at' => optional($this->connected_at)?->toIso8601String(),
            'user_token_expires_at' => optional($this->user_token_expires_at)?->toIso8601String(),
            'data_access_expires_at' => optional($this->data_access_expires_at)?->toIso8601String(),
            'reauth_required_at' => optional($this->reauth_required_at)?->toIso8601String(),
            'last_error_code' => $this->last_error_code,
            // Sanitized operator guidance only — never tokens or raw Graph payloads.
            'last_error_message' => $this->publicErrorGuidance(),
            'pages' => MetaPageResource::collection($this->whenLoaded('pages')),
            // Intentionally omit user_access_token.
        ];
    }
}
