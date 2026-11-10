<?php

namespace App\Enums;

enum ConsentChannel: string
{
    case Email = 'email';
    case Sms = 'sms';
    case Push = 'push';
}
