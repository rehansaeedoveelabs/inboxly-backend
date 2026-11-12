<?php

namespace App\Enums;

enum ShopifyComplianceTopic: string
{
    case CustomersDataRequest = 'customers/data_request';
    case CustomersRedact = 'customers/redact';
    case ShopRedact = 'shop/redact';
}
