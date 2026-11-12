<?php

namespace App\Http\Resources;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Campaign */
class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_id' => $this->store_id,
            'created_by' => $this->created_by,
            'name' => $this->name,
            'channel' => $this->channel?->value,
            'status' => $this->status?->value,
            'subject' => $this->subject,
            'preview_text' => $this->preview_text,
            'body' => $this->body,
            'audience_type' => $this->audience_type?->value,
            'segment_id' => $this->segment_id,
            'scheduled_at' => $this->scheduled_at,
            'audience_count' => $this->audience_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
