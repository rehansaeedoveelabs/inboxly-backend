<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyAbandonedCheckout extends Model
{
    use HasUlids;

    protected $fillable = [
        'store_id',
        'shopify_checkout_id',
        'checkout_token',
        'contact_id',
        'email',
        'abandoned_checkout_url',
        'total_price',
        'currency_code',
        'abandoned_at',
        'recovered_at',
        'enrolled_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
            'abandoned_at' => 'datetime',
            'recovered_at' => 'datetime',
            'enrolled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isRecovered(): bool
    {
        return $this->recovered_at !== null;
    }
}
