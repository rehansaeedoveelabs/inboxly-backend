<?php

namespace App\Enums;

enum ShopifyCustomerIdentityStatus: string
{
    case Tracked = 'tracked';
    case Unlinked = 'unlinked';
    case ShopifyDeleted = 'shopify_deleted';
}
