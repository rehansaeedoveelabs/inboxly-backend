<?php

namespace App\Models;

use App\Enums\MetaPublicationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignMetaPublication extends Model
{
    use HasUlids;

    protected $fillable = [
        'store_id',
        'campaign_id',
        'meta_page_id',
        'status',
        'simulated',
        'idempotency_key',
        'content_hash',
        'publish_intent',
        'message_snapshot',
        'link_snapshot',
        'asset_id',
        'provider_post_id',
        'provider',
        'attempts',
        'error_code',
        'error_message',
        'error_retryable',
        'queued_at',
        'publishing_started_at',
        'published_at',
        'failed_at',
        'lease_expires_at',
        'lease_token',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => MetaPublicationStatus::class,
            'simulated' => 'boolean',
            'attempts' => 'integer',
            'error_retryable' => 'boolean',
            'queued_at' => 'datetime',
            'publishing_started_at' => 'datetime',
            'published_at' => 'datetime',
            'failed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function metaPage(): BelongsTo
    {
        return $this->belongsTo(MetaPage::class, 'meta_page_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public static function makeContentHash(string $message, ?string $link, ?string $assetId): string
    {
        return hash('sha256', implode('|', [
            trim($message),
            $link !== null ? trim($link) : '',
            $assetId ?? '',
        ]));
    }

    public static function makeIdempotencyKey(
        string $campaignId,
        string $metaPageRowId,
        string $contentHash,
        ?string $publishIntent = null,
    ): string {
        $base = $campaignId.':'.$metaPageRowId.':'.$contentHash;

        return $publishIntent ? $base.':'.$publishIntent : $base;
    }
}
