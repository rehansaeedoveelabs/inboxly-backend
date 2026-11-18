<?php

namespace App\Http\Resources;

use App\Models\Automation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Automation */
class AutomationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'created_by' => $this->created_by,
            'name' => $this->name,
            'status' => $this->status?->value,
            'trigger_type' => $this->trigger_type?->value,
            'steps' => AutomationStepResource::collection($this->whenLoaded('steps')),
            'enrollments_count' => $this->whenCounted('enrollments'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
