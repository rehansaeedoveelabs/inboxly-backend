<?php

namespace App\Actions;

use App\Models\Segment;
use App\Models\Store;
use App\Support\Segments\SegmentRules;

class CreateSegment
{
    /**
     * Rules must already be validated (see SegmentRules::validate); they are normalised before saving.
     *
     * @param  array{name: string, description?: ?string, rules: array<string, mixed>}  $data
     */
    public function handle(Store $store, array $data): Segment
    {
        return $store->segments()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'rules' => SegmentRules::normalize($data['rules']),
        ]);
    }
}
