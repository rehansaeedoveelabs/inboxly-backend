<?php

namespace App\Models;

use App\Enums\ShopifyIdentityConflictStatus;
use App\Enums\ShopifyIdentityConflictType;
use Database\Factories\ShopifyIdentityConflictFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Explicit Admin-resolution conflict for a Shopify customer identity.
 * match_signals / resolution_meta must never store raw email or phone.
 */
class ShopifyIdentityConflict extends Model
{
    /** @use HasFactory<ShopifyIdentityConflictFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'shopify_customer_identity_id',
        'candidate_contact_id',
        'conflict_type',
        'status',
        'match_signals',
        'resolved_at',
        'resolved_by_user_id',
        'resolution_code',
        'resolution_meta',
    ];

    protected function casts(): array
    {
        return [
            'conflict_type' => ShopifyIdentityConflictType::class,
            'status' => ShopifyIdentityConflictStatus::class,
            'match_signals' => 'array',
            'resolved_at' => 'datetime',
            'resolution_meta' => 'array',
        ];
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(ShopifyCustomerIdentity::class, 'shopify_customer_identity_id');
    }

    public function candidateContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'candidate_contact_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }
}
