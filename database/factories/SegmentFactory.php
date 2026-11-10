<?php

namespace Database\Factories;

use App\Models\Segment;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'name' => fake()->words(3, true),
            'description' => null,
            'rules' => ['operator' => 'and', 'conditions' => []],
        ];
    }
}
