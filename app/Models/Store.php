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

    /**
     * Shopify access/refresh tokens are intentionally omitted from $fillable so
     * HTTP form requests cannot mass-assign them. OAuth code must set them
     * explicitly (e.g. forceFill / direct attribute assignment).
     */
    protected $fillable = [
        'workspace_id', 'name', 'domain', 'platform', 'connection_status',
        'timezone', 'currency', 'metadata', 'is_demo',
        'shopify_shop_id', 'shopify_shop_domain', 'shopify_scopes',
        'shopify_access_token_expires_at', 'shopify_refresh_token_expires_at',
    ];

    /**
     * Never serialize Shopify secrets onto arrays/JSON accidentally.
     *
     * @var list<string>
     */
    protected $hidden = [
        'shopify_access_token',
        'shopify_refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'platform' => StorePlatform::class,
            'connection_status' => StoreConnectionStatus::class,
            'metadata' => 'array',
            'is_demo' => 'boolean',
            'shopify_access_token' => 'encrypted',
            'shopify_refresh_token' => 'encrypted',
            'shopify_scopes' => 'array',
            'shopify_access_token_expires_at' => 'datetime',
            'shopify_refresh_token_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Invariant: a demo store can never look like a connected store,
        // and a real store can never claim to be "demo".
        static::saving(function (Store $store) {
            if ($store->is_demo) {
                $store->connection_status = StoreConnectionStatus::Demo;
                // Demo stores must never retain platform credentials.
                $store->shopify_shop_id = null;
                $store->shopify_shop_domain = null;
                $store->shopify_access_token = null;
                $store->shopify_refresh_token = null;
                $store->shopify_access_token_expires_at = null;
                $store->shopify_refresh_token_expires_at = null;
                $store->shopify_scopes = null;
            } elseif ($store->connection_status === StoreConnectionStatus::Demo) {
                $store->connection_status = StoreConnectionStatus::Pending;
            }
        });

        // Soft-delete releases globally-unique Shopify identity so the shop can reconnect.
        static::softDeleted(function (Store $store) {
            $store->forceFill([
                'shopify_shop_id' => null,
                'shopify_shop_domain' => null,
                'shopify_access_token' => null,
                'shopify_refresh_token' => null,
                'shopify_access_token_expires_at' => null,
                'shopify_refresh_token_expires_at' => null,
                'shopify_scopes' => null,
                'connection_status' => $store->is_demo
                    ? StoreConnectionStatus::Demo
                    : StoreConnectionStatus::Disconnected,
            ])->saveQuietly();
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

    public function contactExports(): HasMany
    {
        return $this->hasMany(ContactExport::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function isConnected(): bool
    {
        return ! $this->is_demo && $this->connection_status === StoreConnectionStatus::Connected;
    }

    public function hasShopifyCredentials(): bool
    {
        return filled($this->shopify_shop_domain)
            && filled($this->shopify_access_token)
            && filled($this->shopify_refresh_token);
    }

    /**
     * True when the access token is missing an expiry or will expire within the skew window.
     */
    public function accessTokenNeedsRefresh(?int $skewSeconds = null): bool
    {
        if (! $this->hasShopifyCredentials()) {
            return true;
        }

        if ($this->shopify_access_token_expires_at === null) {
            return true;
        }

        $skew = max(0, $skewSeconds ?? (int) config('services.shopify.token_refresh_skew_seconds', 120));

        return $this->shopify_access_token_expires_at->lessThanOrEqualTo(now()->addSeconds($skew));
    }

    public function refreshTokenIsExpired(): bool
    {
        if ($this->shopify_refresh_token_expires_at === null) {
            // Missing refresh expiry is treated as unusable — never assume infinite lifetime.
            return true;
        }

        return $this->shopify_refresh_token_expires_at->lessThanOrEqualTo(now());
    }

    /**
     * @param  array{
     *     shop_id?: string|null,
     *     shop_domain: string,
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at?: \DateTimeInterface|string|null,
     *     refresh_token_expires_at?: \DateTimeInterface|string|null,
     *     scopes?: list<string>|null
     * }  $credentials
     */
    public function storeShopifyCredentials(array $credentials): void
    {
        $this->forceFill([
            'shopify_shop_id' => $credentials['shop_id'] ?? $this->shopify_shop_id,
            'shopify_shop_domain' => mb_strtolower(trim($credentials['shop_domain'])),
            'shopify_access_token' => $credentials['access_token'],
            'shopify_refresh_token' => $credentials['refresh_token'],
            'shopify_access_token_expires_at' => $credentials['access_token_expires_at'] ?? null,
            'shopify_refresh_token_expires_at' => $credentials['refresh_token_expires_at'] ?? null,
            'shopify_scopes' => $credentials['scopes'] ?? [],
            'connection_status' => StoreConnectionStatus::Connected,
            'is_demo' => false,
        ])->save();
    }

    /**
     * Persist a rotated offline token pair without clearing shop identity.
     *
     * @param  array{
     *     access_token: string,
     *     refresh_token: string,
     *     access_token_expires_at: \DateTimeInterface|string,
     *     refresh_token_expires_at: \DateTimeInterface|string,
     *     scopes?: list<string>|null
     * }  $credentials
     */
    public function rotateShopifyCredentials(array $credentials): void
    {
        $this->forceFill([
            'shopify_access_token' => $credentials['access_token'],
            'shopify_refresh_token' => $credentials['refresh_token'],
            'shopify_access_token_expires_at' => $credentials['access_token_expires_at'],
            'shopify_refresh_token_expires_at' => $credentials['refresh_token_expires_at'],
            'shopify_scopes' => array_key_exists('scopes', $credentials)
                ? ($credentials['scopes'] ?? [])
                : $this->shopify_scopes,
            'connection_status' => StoreConnectionStatus::Connected,
            'is_demo' => false,
        ])->save();
    }

    /**
     * Clear secrets after a permanent credential failure while preserving shop
     * identity so the merchant can reconnect the same store record.
     */
    public function invalidateShopifyCredentials(): void
    {
        $this->forceFill([
            'shopify_access_token' => null,
            'shopify_refresh_token' => null,
            'shopify_access_token_expires_at' => null,
            'shopify_refresh_token_expires_at' => null,
            'connection_status' => $this->is_demo
                ? StoreConnectionStatus::Demo
                : StoreConnectionStatus::Error,
        ])->save();
    }

    /**
     * Merchant disconnect: clear secrets and mark disconnected while keeping
     * shop identity so the same store record can reconnect later.
     */
    public function disconnectShopify(): void
    {
        $this->forceFill([
            'shopify_access_token' => null,
            'shopify_refresh_token' => null,
            'shopify_access_token_expires_at' => null,
            'shopify_refresh_token_expires_at' => null,
            'shopify_scopes' => [],
            'connection_status' => $this->is_demo
                ? StoreConnectionStatus::Demo
                : StoreConnectionStatus::Disconnected,
        ])->save();
    }

    public function clearShopifyCredentials(): void
    {
        $this->forceFill([
            'shopify_shop_id' => null,
            'shopify_shop_domain' => null,
            'shopify_access_token' => null,
            'shopify_refresh_token' => null,
            'shopify_access_token_expires_at' => null,
            'shopify_refresh_token_expires_at' => null,
            'shopify_scopes' => null,
            'connection_status' => $this->is_demo
                ? StoreConnectionStatus::Demo
                : StoreConnectionStatus::Disconnected,
        ])->save();
    }
}
