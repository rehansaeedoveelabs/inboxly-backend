<?php

namespace Database\Factories;

use App\Models\ShopifyProduct;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifyProduct>
 */
class ShopifyProductFactory extends Factory
{
    protected $model = ShopifyProduct::class;

    public function definition(): array
    {
        $id = fake()->unique()->numerify('##########');

        return [
            'store_id' => Store::factory(),
            'shopify_product_gid' => 'gid://shopify/Product/'.$id,
            'title' => fake()->words(3, true),
            'handle' => fake()->unique()->slug(),
            'status' => 'ACTIVE',
            'product_type' => 'Demo',
            'vendor' => fake()->company(),
            'shopify_updated_at' => now(),
            'last_synced_at' => now(),
            'metadata' => ['variant_count' => 1],
        ];
    }
}
