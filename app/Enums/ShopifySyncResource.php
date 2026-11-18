<?php

namespace App\Enums;

enum ShopifySyncResource: string
{
    case Customers = 'customers';
    case Products = 'products';
    case Orders = 'orders';

    public function requiredScope(): string
    {
        return match ($this) {
            self::Customers => 'read_customers',
            self::Products => 'read_products',
            self::Orders => 'read_orders',
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Customers, self::Products, self::Orders];
    }
}
