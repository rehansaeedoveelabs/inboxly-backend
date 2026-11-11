<?php

namespace Database\Factories;

use App\Enums\ContactImportStatus;
use App\Models\ContactImport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactImport>
 */
class ContactImportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'status' => ContactImportStatus::Pending,
            'original_filename' => null,
            'total_rows' => 0,
            'options' => ['rows' => []],
        ];
    }
}
