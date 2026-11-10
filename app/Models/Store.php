<?php

namespace App\Models;

use App\Enums\StoreConnectionStatus;
use App\Enums\StorePlatform;
use Database\Factories\StoreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends Model
{
    /** @use HasFactory<StoreFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'domain', 'platform', 'connection_status',
        'timezone', 'currency', 'metadata', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'platform' => StorePlatform::class,
            'connection_status' => StoreConnectionStatus::class,
            'metadata' => 'array',
            'is_demo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Invariant: a demo store can never look like a connected store,
        // and a real store can never claim to be "demo".
        static::saving(function (Store $store) {
            if ($store->is_demo) {
                $store->connection_status = StoreConnectionStatus::Demo;
            } elseif ($store->connection_status === StoreConnectionStatus::Demo) {
                $store->connection_status = StoreConnectionStatus::Pending;
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(Segment::class);
    }

    public function contactImports(): HasMany
    {
        return $this->hasMany(ContactImport::class);
    }

    public function isConnected(): bool
    {
        return ! $this->is_demo && $this->connection_status === StoreConnectionStatus::Connected;
    }
}
