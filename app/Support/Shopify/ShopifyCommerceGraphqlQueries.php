<?php

namespace App\Support\Shopify;

/**
 * Admin GraphQL documents for commerce sync thin slice.
 * Customers intentionally request id + updatedAt only (no PII fields).
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
