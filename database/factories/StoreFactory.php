<?php

namespace Database\Factories;

use App\Enums\StoreConnectionStatus;
use App\Models\Store;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->company().' Store',
            'domain' => fake()->unique()->domainWord().'.myshopify.com',
            'platform' => 'shopify',
            'connection_status' => StoreConnectionStatus::Pending,
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_demo' => false,
        ];
    }

    public function demo(): static
    {
        return $this->state(fn () => ['is_demo' => true, 'domain' => null]);
    }

    /**
     * Apply Shopify identity + encrypted offline tokens (for storage tests / future OAuth).
     * Tokens must be assigned via forceFill — they are not mass-assignable.
     */
    public function withShopifyCredentials(?string $shopDomain = null): static
    {
        $domain = $shopDomain ?? (fake()->unique()->domainWord().'.myshopify.com');

        return $this->state(fn () => [
            'domain' => $domain,
            'shopify_shop_id' => (string) fake()->unique()->numerify('##########'),
            'shopify_shop_domain' => $domain,
            'shopify_scopes' => [],
            'shopify_access_token_expires_at' => now()->addHour(),
            'shopify_refresh_token_expires_at' => now()->addDays(90),
            'connection_status' => StoreConnectionStatus::Connected,
            'is_demo' => false,
        ])->afterMaking(function (Store $store) {
            $store->shopify_access_token = 'shpat_test_access_token';
            $store->shopify_refresh_token = 'shprt_test_refresh_token';
        })->afterCreating(function (Store $store) {
            $store->forceFill([
                'shopify_access_token' => 'shpat_test_access_token',
                'shopify_refresh_token' => 'shprt_test_refresh_token',
            ])->saveQuietly();
        });
    }
}
