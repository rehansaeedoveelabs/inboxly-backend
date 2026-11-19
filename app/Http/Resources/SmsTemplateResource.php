<?php

namespace App\Http\Resources;

use App\Models\SmsTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SmsTemplate */
class SmsTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'created_by' => $this->created_by,
            'name' => $this->name,
            'category' => $this->category,
            'status' => $this->status?->value,
            'body' => $this->body,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
