<?php

namespace App\Models;

use App\Enums\MetaConnectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaConnection extends Model
{
    use HasUlids;

    protected $fillable = [
        'store_id',
        'meta_user_id',
        'status',
        'scopes',
        'connected_at',
        'reauth_required_at',
        'last_error_code',
        'last_error_message',
        'user_token_expires_at',
        'data_access_expires_at',
    ];

    protected $hidden = [
        'user_access_token',
    ];

    protected function casts(): array
    {
        return [
            'user_access_token' => 'encrypted',
            'user_token_expires_at' => 'datetime',
            'data_access_expires_at' => 'datetime',
            'scopes' => 'array',
            'status' => MetaConnectionStatus::class,
            'connected_at' => 'datetime',
            'reauth_required_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(MetaPage::class);
    }

    public function isUsable(): bool
    {
        return $this->unusableReason() === null;
    }

    /**
     * Safe reason code when the connection cannot be used for Graph calls.
     * Missing expiry fields do not invent expiration.
     */
    public function unusableReason(): ?string
    {
        if ($this->status !== MetaConnectionStatus::Connected || ! filled($this->user_access_token)) {
            return $this->status === MetaConnectionStatus::NeedsReauth
                ? 'needs_reauth'
                : 'not_connected';
        }

        $skew = max(0, (int) config('services.meta.token_expiry_skew_seconds', 120));
        $deadline = now()->addSeconds($skew);

        if ($this->user_token_expires_at !== null
            && $this->user_token_expires_at->lessThanOrEqualTo($deadline)) {
            return 'user_token_expired';
        }

        // Only enforce when Meta provided a positive data_access_expires_at via debug_token.
        if ($this->data_access_expires_at !== null
            && $this->data_access_expires_at->lessThanOrEqualTo($deadline)) {
            return 'data_access_expired';
        }

        return null;
    }

    public function hasGrantedScope(string $scope): bool
    {
        $scopes = is_array($this->scopes) ? $this->scopes : [];

        return in_array($scope, $scopes, true);
    }

    /**
     * Permissions required to publish Page posts (Meta Pages API / feed).
     *
     * @return list<string>
     */
    public function missingPublishScopes(): array
    {
        $required = [
            'pages_show_list',
            'pages_read_engagement',
            'pages_manage_posts',
        ];

        return array_values(array_filter(
            $required,
            fn (string $scope) => ! $this->hasGrantedScope($scope),
        ));
    }

    public function markNeedsReauth(?string $code = null, ?string $message = null): void
    {
        $this->forceFill([
            'status' => MetaConnectionStatus::NeedsReauth,
            'reauth_required_at' => $this->reauth_required_at ?? now(),
            'last_error_code' => $code,
            'last_error_message' => $message !== null ? mb_substr($this->sanitizePublicError($message), 0, 500) : null,
        ])->save();
    }

    /**
     * Operator-facing guidance without credentials or stack traces.
     */
    public function publicErrorGuidance(): ?string
    {
        $message = is_string($this->last_error_message) ? trim($this->last_error_message) : '';
        if ($message !== '') {
            return $message;
        }

        return match ($this->last_error_code) {
            'user_token_expired' => 'The Meta user token has expired. Reconnect Meta for this store.',
            'data_access_expired' => 'Meta data access for this connection has expired. Reconnect Meta for this store.',
            'invalid_token' => 'Meta rejected the stored credentials. Reconnect Meta for this store.',
            'permission_denied' => 'Meta permissions are insufficient. Reconnect Meta and grant the required Page permissions.',
            default => $this->status === MetaConnectionStatus::NeedsReauth
                ? 'Meta requires reauthentication before this store can publish.'
                : null,
        };
    }

    /**
     * @param  array{
     *     meta_user_id: string,
     *     user_access_token: string,
     *     user_token_expires_at?: \DateTimeInterface|string|null,
     *     data_access_expires_at?: \DateTimeInterface|string|null,
     *     scopes?: list<string>|null,
     * }  $credentials
     */
    public function storeCredentials(array $credentials): void
    {
        $this->forceFill([
            'meta_user_id' => $credentials['meta_user_id'],
            'user_access_token' => $credentials['user_access_token'],
            'user_token_expires_at' => $credentials['user_token_expires_at'] ?? null,
            'data_access_expires_at' => array_key_exists('data_access_expires_at', $credentials)
                ? $credentials['data_access_expires_at']
                : $this->data_access_expires_at,
            'scopes' => $credentials['scopes'] ?? $this->scopes,
            'status' => MetaConnectionStatus::Connected,
            'connected_at' => $this->connected_at ?? now(),
            'reauth_required_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    private function sanitizePublicError(string $message): string
    {
        $clean = preg_replace('/\bEA[A-Za-z0-9]{20,}\b/', '[redacted]', $message) ?? $message;
        $clean = preg_replace('/access_token=[^&\s]+/i', 'access_token=[redacted]', $clean) ?? $clean;

        return $clean;
    }
}
