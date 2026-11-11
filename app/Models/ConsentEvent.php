<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use Database\Factories\ConsentEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only audit row: created once, never updated or deleted by the application.
 */
class ConsentEvent extends Model
{
    /** @use HasFactory<ConsentEventFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['contact_id', 'channel', 'status', 'source', 'meta'];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'status' => ConsentStatus::class,
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Consent events are append-only.'));
        static::deleting(fn () => throw new LogicException('Consent events are append-only.'));
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
