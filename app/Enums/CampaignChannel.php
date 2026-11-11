<?php

namespace App\Enums;

enum CampaignChannel: string
{
    case Email = 'email';
    case Sms = 'sms';

    public function toConsentChannel(): ConsentChannel
    {
        return match ($this) {
            self::Email => ConsentChannel::Email,
            self::Sms => ConsentChannel::Sms,
        };
    }
}
