<?php

namespace App\Actions\Shopify;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyComplianceRequestEvent;
use App\Models\User;

class RecordShopifyComplianceEvent
{
    /**
     * @param  array<string, mixed>|null  $meta  Safe codes/counts only — never raw PII.
     */
    public function handle(
        ShopifyComplianceRequest $request,
        ShopifyComplianceRequestEventType $type,
        ?User $actor = null,
        ?array $meta = null,
    ): ShopifyComplianceRequestEvent {
        return ShopifyComplianceRequestEvent::query()->create([
            'compliance_request_id' => $request->getKey(),
            'actor_user_id' => $actor?->getKey(),
            'event_type' => $type,
            'meta' => $meta,
        ]);
    }
}
