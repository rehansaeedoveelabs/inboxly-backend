<?php

namespace App\Enums;

enum AutomationStepType: string
{
    case Delay = 'delay';
    case SendEmail = 'send_email';
    /** Simple linear branch: evaluate predicate, then jump to next/end/absolute position. */
    case Condition = 'condition';
}
