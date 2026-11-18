<?php

namespace App\Models;

use Database\Factories\ShopifyOrderFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Synced Shopify order snapshot. Never stores customer email/phone/shipping address.
 */
class ShopifyOrder extends Model
{
    /** @use HasFactory<ShopifyOrderFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'shopify_order_gid',
        'name',
        'order_number',
        'financial_status',
        'fulfillment_status',
        'currency_code',
        'total_price',
        'shopify_customer_gid',
        'processed_at',
        'shopify_updated_at',
        'last_synced_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'order_number' => 'integer',
            'total_price' => 'decimal:2',
            'processed_at' => 'datetime',
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
