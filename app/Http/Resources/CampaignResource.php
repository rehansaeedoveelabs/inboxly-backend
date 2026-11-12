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
            'content_format' => $this->content_format?->value ?? 'plain',
            'html_body' => $this->html_body,
            'content_document' => $this->content_document,
            'email_template_id' => $this->email_template_id,
            'audience_type' => $this->audience_type?->value,
            'segment_id' => $this->segment_id,
            'scheduled_at' => $this->scheduled_at,
            'audience_count' => $this->audience_count,
            'sending_started_at' => $this->sending_started_at,
            'sent_at' => $this->sent_at,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'skipped_count' => $this->skipped_count,
            'send_error' => $this->send_error,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
