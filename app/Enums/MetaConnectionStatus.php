<?php

namespace App\Enums;

enum MetaConnectionStatus: string
{
    case Connected = 'connected';
    case NeedsReauth = 'needs_reauth';
}
