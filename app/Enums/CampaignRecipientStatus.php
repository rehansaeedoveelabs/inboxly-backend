<?php

namespace App\Enums;

enum CampaignRecipientStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Bounced = 'bounced';
    case Unsubscribed = 'unsubscribed';
}
