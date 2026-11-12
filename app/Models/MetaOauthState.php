<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MetaOauthState extends Model
{
    use HasUlids;

    protected $fillable = [
        'state_hash',
        'user_id',
        'workspace_id',
        'store_id',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Create a cryptographically random state and return [plaintext, hash].
     *
     * @return array{0: string, 1: string}
     */
    public static function issuePlaintextAndHash(): array
    {
        $plaintext = Str::random(64);

        return [$plaintext, self::hashState($plaintext)];
    }

    public static function hashState(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
