<?php

namespace App\Http\Resources;

use App\Models\ShopifyComplianceRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ShopifyComplianceRequest */
class ShopifyComplianceRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'topic' => $this->topic->value,
            'status' => $this->status->value,
            'shop_domain' => $this->shop_domain,
            'shopify_customer_id' => $this->shopify_customer_id,
            'shopify_data_request_id' => $this->shopify_data_request_id,
            'attempt_count' => $this->attempt_count,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'processing_checkpoint' => $this->processing_checkpoint,
            'legal_checklist' => $this->legal_checklist,
            'inventory_summary' => $this->inventory_summary,
            'inventory_artifact_ready' => $this->hasInventoryArtifact(),
            'received_at' => $this->received_at,
            'processing_started_at' => $this->processing_started_at,
            'processing_lease_expires_at' => $this->processing_lease_expires_at,
            'completed_at' => $this->completed_at,
            'failed_at' => $this->failed_at,
            'awaiting_policy_at' => $this->awaiting_policy_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // Never expose inventory_path, correlation_path, payload_sha256 raw body, email, or phone.
        ];
    }
}
