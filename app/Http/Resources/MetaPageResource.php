<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\MetaPage */
class MetaPageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'meta_page_id' => $this->meta_page_id,
            'name' => $this->name,
            'tasks' => $this->tasks ?? [],
            'is_default' => (bool) $this->is_default,
            'selected_at' => optional($this->selected_at)?->toIso8601String(),
            // Intentionally omit page_access_token.
        ];
    }
}
