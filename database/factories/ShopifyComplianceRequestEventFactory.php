<?php

namespace Database\Factories;

use App\Enums\ShopifyComplianceRequestEventType;
use App\Models\ShopifyComplianceRequest;
use App\Models\ShopifyComplianceRequestEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyComplianceRequestEvent>
 */
class ShopifyComplianceRequestEventFactory extends Factory
{
    protected $model = ShopifyComplianceRequestEvent::class;

    public function definition(): array
    {
        return [
            'compliance_request_id' => ShopifyComplianceRequest::factory(),
            'actor_user_id' => null,
            'event_type' => ShopifyComplianceRequestEventType::Received,
            'meta' => ['source' => 'factory'],
        ];
    }

    public function retryRequested(): static
    {
        return $this->state(fn () => [
            'event_type' => ShopifyComplianceRequestEventType::RetryRequested,
            'meta' => ['source' => 'admin_retry'],
        ]);
    }
}
