<?php

namespace App\Support\Shopify;

/**
 * Admin GraphQL documents for commerce sync.
 * Customers include marketing identity fields so Audience contacts can be listed.
 */
final class ShopifyCommerceGraphqlQueries
{
    public const CUSTOMERS = <<<'GRAPHQL'
    query SyncCustomers($first: Int!, $after: String) {
      customers(first: $first, after: $after) {
        edges {
          node {
            id
            updatedAt
            firstName
            lastName
            defaultEmailAddress {
              emailAddress
            }
            defaultPhoneNumber {
              phoneNumber
            }
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
    GRAPHQL;

    public const PRODUCTS = <<<'GRAPHQL'
    query SyncProducts($first: Int!, $after: String) {
      products(first: $first, after: $after) {
        edges {
          node {
            id
            title
            handle
            status
            productType
            vendor
            updatedAt
            featuredImage {
              url
            }
            priceRangeV2 {
              minVariantPrice {
                amount
                currencyCode
              }
              maxVariantPrice {
                amount
                currencyCode
              }
            }
            variantsCount {
              count
            }
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
    GRAPHQL;

    public const ORDERS = <<<'GRAPHQL'
    query SyncOrders($first: Int!, $after: String) {
      orders(first: $first, after: $after) {
        edges {
          node {
            id
            name
            legacyResourceId
            displayFinancialStatus
            displayFulfillmentStatus
            processedAt
            updatedAt
            totalPriceSet {
              shopMoney {
                amount
                currencyCode
              }
            }
            customer {
              id
            }
          }
        }
        pageInfo {
          hasNextPage
          endCursor
        }
      }
    }
    GRAPHQL;
}
