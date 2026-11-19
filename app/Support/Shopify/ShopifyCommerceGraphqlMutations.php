<?php

namespace App\Support\Shopify;

final class ShopifyCommerceGraphqlMutations
{
    public const CUSTOMER_UPDATE = <<<'GRAPHQL'
    mutation CustomerUpdate($input: CustomerInput!) {
      customerUpdate(input: $input) {
        customer {
          id
          firstName
          lastName
          defaultEmailAddress { emailAddress }
          defaultPhoneNumber { phoneNumber }
        }
        userErrors {
          field
          message
        }
      }
    }
    GRAPHQL;
}
