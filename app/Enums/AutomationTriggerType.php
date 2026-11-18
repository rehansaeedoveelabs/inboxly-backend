<?php

namespace App\Enums;

enum AutomationTriggerType: string
{
    case ContactEmailSubscribed = 'contact.email_subscribed';
    case ContactTagAdded = 'contact.tag_added';
}
