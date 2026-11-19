<?php

namespace App\Enums;

enum ShopifyCustomerIdentityLinkSource: string
{
    case ManualAdmin = 'manual_admin';
    case ShopifySync = 'shopify_sync';
    case InboxlyWriteback = 'inboxly_writeback';
}

