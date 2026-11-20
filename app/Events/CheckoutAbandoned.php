<?php

namespace App\Events;

/**
 * Fired when a Shopify checkout is abandoned and linked to a contact.
 */
class CheckoutAbandoned
{
    public function __construct(
        public readonly string $contactId,
        public readonly string $storeId,
        public readonly string $abandonedCheckoutId,
    ) {}
}
