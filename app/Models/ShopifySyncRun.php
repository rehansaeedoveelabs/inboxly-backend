<?php

namespace App\Models;

use App\Enums\ShopifySyncResource;
use App\Enums\ShopifySyncResourceStatus;
use App\Enums\ShopifySyncRunStatus;
use Database\Factories\ShopifySyncRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySyncRun extends Model
{
    /** @use HasFactory<ShopifySyncRunFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'store_id',
        'resources',
        'status',
        'customers_status',
        'products_status',
        'orders_status',
        'customers_cursor',
        'products_cursor',
        'orders_cursor',
        'customers_synced',
        'products_synced',
        'orders_synced',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
        'lease_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'resources' => 'array',
            'status' => ShopifySyncRunStatus::class,
            'customers_status' => ShopifySyncResourceStatus::class,
            'products_status' => ShopifySyncResourceStatus::class,
            'orders_status' => ShopifySyncResourceStatus::class,
            'customers_synced' => 'integer',
            'products_synced' => 'integer',
            'orders_synced' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    /**
     * @return list<ShopifySyncResource>
     */
    public function requestedResources(): array
    {
        $raw = is_array($this->resources) ? $this->resources : [];
        $out = [];
        foreach ($raw as $value) {
            if (! is_string($value)) {
                continue;
            }
            $resource = ShopifySyncResource::tryFrom($value);
            if ($resource !== null) {
                $out[] = $resource;
            }
        }

        return $out;
    }

    public function resourceStatus(ShopifySyncResource $resource): ShopifySyncResourceStatus
    {
        return match ($resource) {
            ShopifySyncResource::Customers => $this->customers_status,
            ShopifySyncResource::Products => $this->products_status,
            ShopifySyncResource::Orders => $this->orders_status,
        };
    }

    public function setResourceStatus(ShopifySyncResource $resource, ShopifySyncResourceStatus $status): void
    {
        match ($resource) {
            ShopifySyncResource::Customers => $this->customers_status = $status,
            ShopifySyncResource::Products => $this->products_status = $status,
            ShopifySyncResource::Orders => $this->orders_status = $status,
        };
    }

    public function cursorFor(ShopifySyncResource $resource): ?string
    {
        return match ($resource) {
            ShopifySyncResource::Customers => $this->customers_cursor,
            ShopifySyncResource::Products => $this->products_cursor,
            ShopifySyncResource::Orders => $this->orders_cursor,
        };
    }

    public function setCursorFor(ShopifySyncResource $resource, ?string $cursor): void
    {
        match ($resource) {
            ShopifySyncResource::Customers => $this->customers_cursor = $cursor,
            ShopifySyncResource::Products => $this->products_cursor = $cursor,
            ShopifySyncResource::Orders => $this->orders_cursor = $cursor,
        };
    }

    public function syncedCountFor(ShopifySyncResource $resource): int
    {
        return match ($resource) {
            ShopifySyncResource::Customers => (int) $this->customers_synced,
            ShopifySyncResource::Products => (int) $this->products_synced,
            ShopifySyncResource::Orders => (int) $this->orders_synced,
        };
    }

    public function addSyncedCount(ShopifySyncResource $resource, int $delta): void
    {
        match ($resource) {
            ShopifySyncResource::Customers => $this->customers_synced = max(0, (int) $this->customers_synced + $delta),
            ShopifySyncResource::Products => $this->products_synced = max(0, (int) $this->products_synced + $delta),
            ShopifySyncResource::Orders => $this->orders_synced = max(0, (int) $this->orders_synced + $delta),
        };
    }

    public function leaseIsActive(): bool
    {
        return $this->lease_expires_at !== null
            && $this->lease_expires_at->isFuture()
            && in_array($this->status, [ShopifySyncRunStatus::Pending, ShopifySyncRunStatus::Running], true);
    }
}
