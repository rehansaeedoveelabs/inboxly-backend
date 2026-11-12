<?php

namespace App\Enums;

enum ShopifyComplianceStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case PartiallyProcessed = 'partially_processed';
    case AwaitingPolicy = 'awaiting_policy';
    case Failed = 'failed';
    case Completed = 'completed';
}
