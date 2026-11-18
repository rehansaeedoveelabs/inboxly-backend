<?php

namespace App\Models;

use App\Enums\ShopifyCustomerIdentityLinkSource;
use App\Enums\ShopifyCustomerIdentityStatus;
use Database\Factories\ShopifyCustomerIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Store-scoped Shopify Customer GID identity. Optional link to an Inboxly contact.
 * No PII snapshots — metadata must remain non-PII flags/counts only.
 */
class ShopifyCustomerIdentity extends Model
{
    /** @use HasFactory<ShopifyCustomerIdentityFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'shopify_customer_gid',
        'contact_id',
        'status',
        'link_source',
        'linked_at',
        'unlinked_at',
        'shopify_deleted_at',
        'shopify_updated_at',
        'last_synced_at',
        'last_seen_at',
        'sync_error_code',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => ShopifyCustomerIdentityStatus::class,
            'link_source' => ShopifyCustomerIdentityLinkSource::class,
            'linked_at' => 'datetime',
            'unlinked_at' => 'datetime',
            'shopify_deleted_at' => 'datetime',
            'shopify_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_seen_at' => 'datetime',
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

    public function conflicts(): HasMany
    {
        return $this->hasMany(ShopifyIdentityConflict::class);
    }
}
