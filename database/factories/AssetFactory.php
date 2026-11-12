<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        $id = (string) Str::ulid();

        return [
            'id' => $id,
            'store_id' => Store::factory(),
            'uploaded_by' => User::factory(),
            'name' => fake()->words(2, true),
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'byte_size' => 1024,
            'width' => 800,
            'height' => 600,
            'disk' => 'local',
            'path' => 'assets/factory/'.$id.'.jpg',
            'alt' => null,
        ];
    }
}
