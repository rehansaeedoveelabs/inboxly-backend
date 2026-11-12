<?php

namespace App\Models;

use App\Enums\ShopifyComplianceRequestEventType;
use Database\Factories\ShopifyComplianceRequestEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row for compliance request processing.
 * Created once; never updated or deleted by the application (ConsentEvent pattern).
 */
class ShopifyComplianceRequestEvent extends Model
{
    /** @use HasFactory<ShopifyComplianceRequestEventFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'compliance_request_id',
        'actor_user_id',
        'event_type',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ShopifyComplianceRequestEventType::class,
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Compliance request events are append-only.'));
        static::deleting(fn () => throw new LogicException('Compliance request events are append-only.'));
    }

    public function complianceRequest(): BelongsTo
    {
        return $this->belongsTo(ShopifyComplianceRequest::class, 'compliance_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
