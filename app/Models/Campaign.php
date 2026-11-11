<?php

namespace App\Models;

use App\Enums\CampaignAudienceType;
use App\Enums\CampaignChannel;
use App\Enums\CampaignContentFormat;
use App\Enums\CampaignStatus;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'store_id', 'created_by', 'name', 'channel', 'status',
        'subject', 'preview_text', 'body', 'content_format', 'html_body',
        'content_document', 'email_template_id', 'audience_type', 'segment_id',
        'scheduled_at', 'audience_count', 'sending_started_at', 'sent_at',
        'sent_count', 'failed_count', 'skipped_count', 'send_error',
    ];

    protected function casts(): array
    {
        return [
            'channel' => CampaignChannel::class,
            'status' => CampaignStatus::class,
            'content_format' => CampaignContentFormat::class,
            'content_document' => 'array',
            'audience_type' => CampaignAudienceType::class,
            'scheduled_at' => 'datetime',
            'sending_started_at' => 'datetime',
            'sent_at' => 'datetime',
            'audience_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function isDraft(): bool
    {
        return $this->status === CampaignStatus::Draft;
    }

    public function isScheduled(): bool
    {
        return $this->status === CampaignStatus::Scheduled;
    }

    public function isLocked(): bool
    {
        return ! $this->status->isEditable();
    }
}
