<?php

namespace App\Enums;

enum ConsentStatus: string
{
    case Subscribed = 'subscribed';
    case Unsubscribed = 'unsubscribed';
    case Nonsubscribed = 'nonsubscribed';
}
