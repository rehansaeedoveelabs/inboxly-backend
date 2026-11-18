<?php

namespace App\Enums;

enum ShopifyIdentityConflictStatus: string
{
    case Open = 'open';
    case ResolvedLinked = 'resolved_linked';
    case ResolvedDismissed = 'resolved_dismissed';
    case ResolvedIgnored = 'resolved_ignored';
}
