<?php

namespace App\Enums;

enum CampaignAudienceType: string
{
    case Segment = 'segment';
    case AllEligible = 'all_eligible';
}
