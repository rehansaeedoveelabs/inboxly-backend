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
}
