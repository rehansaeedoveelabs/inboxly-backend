<?php

namespace Database\Factories;

use App\Enums\ShopifySyncResourceStatus;
use App\Enums\ShopifySyncRunStatus;
use App\Models\ShopifySyncRun;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopifySyncRun>
 */
class ShopifySyncRunFactory extends Factory
{
    protected $model = ShopifySyncRun::class;

    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'resources' => ['customers', 'products', 'orders'],
            'status' => ShopifySyncRunStatus::Pending,
            'customers_status' => ShopifySyncResourceStatus::Pending,
            'products_status' => ShopifySyncResourceStatus::Pending,
            'orders_status' => ShopifySyncResourceStatus::Pending,
            'customers_synced' => 0,
            'products_synced' => 0,
            'orders_synced' => 0,
        ];
    }
}
