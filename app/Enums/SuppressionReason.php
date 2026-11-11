<?php

namespace App\Enums;

enum SuppressionReason: string
{
    case HardBounce = 'hard_bounce';
    case Complaint = 'complaint';
    case Manual = 'manual';
    case Unsubscribe = 'unsubscribe';
    case InvalidAddress = 'invalid_address';
}
