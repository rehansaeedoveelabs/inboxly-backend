<?php

namespace App\Http\Resources;

use App\Models\AutomationEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AutomationEnrollment */
class AutomationEnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'automation_id' => $this->automation_id,
            'store_id' => $this->store_id,
            'contact_id' => $this->contact_id,
            'status' => $this->status?->value,
            'current_step_position' => $this->current_step_position,
            'next_run_at' => $this->next_run_at,
            'enrolled_at' => $this->enrolled_at,
            'completed_at' => $this->completed_at,
            'cancelled_at' => $this->cancelled_at,
            'cancel_reason' => $this->cancel_reason,
            'contact' => $this->whenLoaded('contact', function () {
                return [
                    'id' => $this->contact?->id,
                    'email' => $this->contact?->email,
                    'first_name' => $this->contact?->first_name,
                    'last_name' => $this->contact?->last_name,
                ];
            }),
            'step_runs' => AutomationStepRunResource::collection($this->whenLoaded('stepRuns')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
