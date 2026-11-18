<?php

namespace App\Models;

use Database\Factories\ShopifyProductFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyProduct extends Model
{
    /** @use HasFactory<ShopifyProductFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'shopify_product_gid',
        'title',
        'handle',
        'status',
        'product_type',
        'vendor',
        'shopify_updated_at',
        'last_synced_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'shopify_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
