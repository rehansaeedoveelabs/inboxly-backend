<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyAdminGraphqlException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin Admin GraphQL transport for commerce sync.
 * Callers must supply a token from ShopifyCredentialService::ensureValidAccessToken.
 */
class ShopifyAdminGraphqlClient
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyAdminGraphqlException
     */
    public function request(string $shopDomain, string $accessToken, string $query, array $variables = []): array
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '' || $accessToken === '') {
            throw new ShopifyAdminGraphqlException(
                'Shop domain and access token are required for Admin GraphQL.',
                'graphql_credentials_missing',
                permanent: true,
            );
        }

        $version = (string) config('services.shopify.api_version', '2026-10');
        $url = "https://{$shopDomain}/admin/api/{$version}/graphql.json";
        $timeout = max(5, (int) config('services.shopify.graphql_timeout_seconds', 20));

        // Shopify rejects `"variables": []` with "Invalid variables parameter."
        // Omit the key when unused; send a JSON object when present.
        $body = ['query' => $query];
        if ($variables !== []) {
            $body['variables'] = $variables;
        }

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout($timeout)->post($url, $body);
        } catch (ConnectionException $e) {
            Log::warning('Shopify Admin GraphQL connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyAdminGraphqlException(
                'Unable to reach Shopify Admin GraphQL.',
                'graphql_network',
                permanent: false,
                previous: $e,
            );
        }

        if ($response->status() === 429) {
            throw new ShopifyAdminGraphqlException(
                'Shopify Admin GraphQL rate limit exceeded.',
                'graphql_throttled',
                permanent: false,
                throttled: true,
            );
        }

        if ($response->serverError()) {
            throw new ShopifyAdminGraphqlException(
                'Shopify Admin GraphQL temporarily unavailable.',
                'graphql_transient_http',
                permanent: false,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new ShopifyAdminGraphqlException(
                'Shopify denied Admin GraphQL access.',
                'graphql_unauthorized',
                permanent: true,
            );
        }

        if (! $response->successful()) {
            throw new ShopifyAdminGraphqlException(
                'Shopify Admin GraphQL HTTP request failed.',
                'graphql_http_failed',
                permanent: $response->clientError(),
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopifyAdminGraphqlException(
                'Shopify returned a malformed Admin GraphQL response.',
                'graphql_malformed_response',
                permanent: false,
            );
        }

        $errors = $payload['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            [$permanent, $throttled, $scope] = $this->classifyGraphqlErrors($errors);

            if ($scope) {
                throw new ShopifyAdminGraphqlException(
                    'Shopify Admin GraphQL rejected the query due to missing access scope.',
                    'graphql_missing_scope',
                    permanent: true,
                );
            }

            throw new ShopifyAdminGraphqlException(
                'Shopify Admin GraphQL returned errors.',
                $throttled ? 'graphql_throttled' : ($permanent ? 'graphql_permanent' : 'graphql_transient'),
                permanent: $permanent,
                throttled: $throttled,
            );
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new ShopifyAdminGraphqlException(
                'Shopify Admin GraphQL response missing data.',
                'graphql_missing_data',
                permanent: false,
            );
        }

        return $data;
    }

    /**
     * Paginate a connection field until exhausted or $maxPages is reached.
     *
     * @param  callable(array<string, mixed>): array{nodes: list<array<string, mixed>>, hasNextPage: bool, endCursor: ?string}  $extract
     * @return array{nodes: list<array<string, mixed>>, next_cursor: ?string, exhausted: bool}
     *
     * @throws ShopifyAdminGraphqlException
     */
    public function paginate(
        string $shopDomain,
        string $accessToken,
        string $query,
        callable $extract,
        ?string $after = null,
        int $pageSize = 50,
        int $maxPages = 5,
    ): array {
        $pageSize = max(1, min(100, $pageSize));
        $maxPages = max(1, $maxPages);
        $nodes = [];
        $cursor = $after;
        $exhausted = false;

        for ($page = 0; $page < $maxPages; $page++) {
            $variables = [
                'first' => $pageSize,
                'after' => $cursor,
            ];

            $data = $this->request($shopDomain, $accessToken, $query, $variables);
            $chunk = $extract($data);

            foreach ($chunk['nodes'] as $node) {
                if (is_array($node)) {
                    $nodes[] = $node;
                }
            }

            if (! $chunk['hasNextPage'] || $chunk['endCursor'] === null || $chunk['endCursor'] === '') {
                $exhausted = true;
                $cursor = null;
                break;
            }

            $cursor = $chunk['endCursor'];
        }

        return [
            'nodes' => $nodes,
            'next_cursor' => $exhausted ? null : $cursor,
            'exhausted' => $exhausted,
        ];
    }

    /**
     * @param  list<mixed>  $errors
     * @return array{0: bool, 1: bool, 2: bool} permanent, throttled, missing_scope
     */
    private function classifyGraphqlErrors(array $errors): array
    {
        $permanent = false;
        $throttled = false;
        $scope = false;

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = strtolower((string) ($error['message'] ?? ''));
            $code = '';
            $extensions = $error['extensions'] ?? null;
            if (is_array($extensions)) {
                $code = strtoupper((string) ($extensions['code'] ?? ''));
            }

            if ($code === 'THROTTLED' || str_contains($message, 'throttl')) {
                $throttled = true;
            }

            if (
                $code === 'ACCESS_DENIED'
                || str_contains($message, 'access denied')
                || str_contains($message, 'not approved to access')
                || str_contains($message, 'missing scope')
                || str_contains($message, 'access scope')
            ) {
                $scope = true;
                $permanent = true;
            }

            if (in_array($code, ['UNAUTHORIZED', 'FORBIDDEN'], true)) {
                $permanent = true;
            }
        }

        if (! $throttled && ! $permanent && ! $scope) {
            // Default GraphQL user errors as permanent for sync safety.
            $permanent = true;
        }

        return [$permanent, $throttled, $scope];
    }
}
