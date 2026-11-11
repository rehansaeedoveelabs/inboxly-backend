<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case PartiallySent = 'partially_sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Sent, self::PartiallySent, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }
}
