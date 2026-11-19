<?php

namespace Database\Factories;

use App\Enums\FormStatus;
use App\Enums\FormType;
use App\Models\Form;
use App\Models\Store;
use App\Models\User;
use App\Support\Forms\FormDefinitionSchema;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Form>
 */
class FormFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'created_by' => User::factory(),
            'public_token' => Str::random(40),
            'name' => fake()->sentence(3),
            'type' => FormType::Popup,
            'status' => FormStatus::Draft,
            'definition' => FormDefinitionSchema::defaultDefinition(),
            'submissions_count' => 0,
        ];
    }
}
