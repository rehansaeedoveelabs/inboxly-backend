<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\CampaignMetaPublication */
class CampaignMetaPublicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->getKey(),
            'store_id' => $this->store_id,
            'campaign_id' => $this->campaign_id,
            'meta_page_id' => $this->meta_page_id,
            'page' => $this->whenLoaded('metaPage', function () {
                return [
                    'id' => $this->metaPage->getKey(),
                    'meta_page_id' => $this->metaPage->meta_page_id,
                    'name' => $this->metaPage->name,
                ];
            }),
            'status' => $this->status?->value ?? $this->status,
            'simulated' => (bool) $this->simulated,
            'content_hash' => $this->content_hash,
            'publish_intent' => $this->publish_intent,
            'message_snapshot' => $this->message_snapshot,
            'link_snapshot' => $this->link_snapshot,
            'asset_id' => $this->asset_id,
            'provider_post_id' => $this->provider_post_id,
            'provider' => $this->provider,
            'attempts' => $this->attempts,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'error_retryable' => (bool) $this->error_retryable,
            'queued_at' => optional($this->queued_at)?->toIso8601String(),
            'publishing_started_at' => optional($this->publishing_started_at)?->toIso8601String(),
            'published_at' => optional($this->published_at)?->toIso8601String(),
            'failed_at' => optional($this->failed_at)?->toIso8601String(),
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
            // Intentionally omit tokens and raw provider payloads.
        ];
    }
}
