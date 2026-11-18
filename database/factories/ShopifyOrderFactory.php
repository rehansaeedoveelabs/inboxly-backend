<?php

namespace Database\Factories;

use App\Models\ShopifyOrder;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyOrder>
 */
class ShopifyOrderFactory extends Factory
{
    protected $model = ShopifyOrder::class;

    public function definition(): array
    {
        $id = fake()->unique()->numerify('##########');

        return [
            'store_id' => Store::factory(),
            'shopify_order_gid' => 'gid://shopify/Order/'.$id,
            'name' => '#'.fake()->numberBetween(1000, 9999),
            'order_number' => fake()->numberBetween(1000, 9999),
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
            'currency_code' => 'USD',
            'total_price' => fake()->randomFloat(2, 10, 200),
            'shopify_customer_gid' => 'gid://shopify/Customer/'.fake()->numerify('##########'),
            'processed_at' => now()->subDay(),
            'shopify_updated_at' => now(),
            'last_synced_at' => now(),
            'metadata' => null,
        ];
    }
}
