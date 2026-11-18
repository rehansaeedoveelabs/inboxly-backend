<?php

namespace Database\Factories;

use App\Enums\ShopifyIdentityConflictStatus;
use App\Enums\ShopifyIdentityConflictType;
use App\Models\ShopifyCustomerIdentity;
use App\Models\ShopifyIdentityConflict;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyIdentityConflict>
 */
class ShopifyIdentityConflictFactory extends Factory
{
    protected $model = ShopifyIdentityConflict::class;

    public function definition(): array
    {
        return [
            'shopify_customer_identity_id' => ShopifyCustomerIdentity::factory(),
            'candidate_contact_id' => null,
            'conflict_type' => ShopifyIdentityConflictType::EmailMatch,
            'status' => ShopifyIdentityConflictStatus::Open,
            'match_signals' => ['email_match' => true],
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'resolution_code' => null,
            'resolution_meta' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn () => [
            'status' => ShopifyIdentityConflictStatus::Open,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
            'resolution_code' => null,
            'resolution_meta' => null,
        ]);
    }
}
