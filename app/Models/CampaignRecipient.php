<?php

namespace App\Models;

use App\Enums\CampaignChannel;
use App\Enums\CampaignRecipientStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignRecipient extends Model
{
    use HasUlids;

    protected $fillable = [
        'campaign_id', 'contact_id', 'channel', 'address', 'status',
        'provider', 'provider_message_id', 'error_code', 'error_message',
        'attempts', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => CampaignChannel::class,
            'status' => CampaignRecipientStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
