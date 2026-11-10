<?php

namespace App\Actions;

use App\Models\Store;
use App\Models\Tag;
use Illuminate\Support\Str;

class CreateTag
{
    public function slugFor(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Callers validate slug uniqueness (see CreateTagRequest); use findOrCreate() for upsert semantics.
     *
     * @param  array{name: string, color?: ?string}  $data
     */
    public function handle(Store $store, array $data): Tag
    {
        return $store->tags()->create([
            'name' => $data['name'],
            'slug' => $this->slugFor($data['name']),
            'color' => $data['color'] ?? null,
        ]);
    }

    public function findOrCreate(Store $store, string $name): Tag
    {
        $slug = $this->slugFor($name);

        return $store->tags()->where('slug', $slug)->first()
            ?? $store->tags()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
