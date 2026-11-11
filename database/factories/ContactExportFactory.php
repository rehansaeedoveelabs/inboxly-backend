<?php

namespace Database\Factories;

use App\Enums\ContactExportStatus;
use App\Models\ContactExport;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactExport>
 */
class ContactExportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'user_id' => User::factory(),
            'status' => ContactExportStatus::Pending,
            'filters' => [],
            'disk' => 'local',
            'path' => null,
            'original_filename' => 'contacts-export.csv',
            'total_rows' => 0,
            'processed_rows' => 0,
            'byte_size' => null,
            'error_summary' => null,
            'expires_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }
}
