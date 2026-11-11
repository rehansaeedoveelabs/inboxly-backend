<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'locale' => 'en',
            'timezone' => 'UTC',
            'source' => 'manual',
            'metadata' => null,
        ];
    }
}
