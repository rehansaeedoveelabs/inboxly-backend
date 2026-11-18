<?php

namespace App\Http\Resources;

use App\Models\AutomationStepRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AutomationStepRun */
class AutomationStepRunResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'enrollment_id' => $this->enrollment_id,
            'automation_step_id' => $this->automation_step_id,
            'attempt' => $this->attempt,
            'status' => $this->status?->value,
            'idempotency_key' => $this->idempotency_key,
            'provider_message_id' => $this->provider_message_id,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at,
            'finished_at' => $this->finished_at,
            'step' => new AutomationStepResource($this->whenLoaded('step')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
