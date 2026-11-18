<?php

namespace Database\Factories;

use App\Enums\ShopifyCustomerIdentityLinkSource;
use App\Enums\ShopifyCustomerIdentityStatus;
use App\Models\Contact;
use App\Models\ShopifyCustomerIdentity;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyCustomerIdentity>
 */
class ShopifyCustomerIdentityFactory extends Factory
{
    protected $model = ShopifyCustomerIdentity::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'shopify_customer_gid' => 'gid://shopify/Customer/'.fake()->unique()->numerify('##########'),
            'contact_id' => null,
            'status' => ShopifyCustomerIdentityStatus::Tracked,
            'link_source' => null,
            'linked_at' => null,
            'unlinked_at' => null,
            'shopify_deleted_at' => null,
            'shopify_updated_at' => null,
            'last_synced_at' => null,
            'last_seen_at' => null,
            'sync_error_code' => null,
            'metadata' => null,
        ];
    }

    public function linkedTo(Contact $contact): static
    {
        return $this->state(fn () => [
            'store_id' => $contact->store_id,
            'contact_id' => $contact->id,
            'status' => ShopifyCustomerIdentityStatus::Tracked,
            'link_source' => ShopifyCustomerIdentityLinkSource::ManualAdmin,
            'linked_at' => now(),
            'unlinked_at' => null,
        ]);
    }

    public function unlinked(): static
    {
        return $this->state(fn () => [
            'contact_id' => null,
            'status' => ShopifyCustomerIdentityStatus::Unlinked,
            'unlinked_at' => now(),
        ]);
    }
}
