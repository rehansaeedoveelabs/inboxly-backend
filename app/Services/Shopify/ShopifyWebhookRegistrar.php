<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyWebhookRegistrationException;
use App\Models\Store;
use App\Support\Shopify\ShopifyWebhookUri;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Registers shop-scoped HTTPS webhook subscriptions via Admin GraphQL.
 *
 * Registration is best-effort relative to OAuth connect (failures never roll
 * back credentials) but must not claim success when Shopify did not confirm.
 */
class ShopifyWebhookRegistrar
{
    /**
     * @return list<string> Topics that were confirmed present after this run.
     *
     * @throws ShopifyWebhookRegistrationException
     */
    public function registerRequiredTopics(Store $store, string $accessToken): array
    {
        $shopDomain = (string) ($store->shopify_shop_domain ?: $store->domain);
        if ($shopDomain === '' || $accessToken === '') {
            throw new ShopifyWebhookRegistrationException(
                'Store is missing shop domain or access token for webhook registration.',
                'webhook_credentials_missing',
                permanent: true,
            );
        }

        $uri = $this->resolvedWebhookUri($store);

        $topics = array_values(array_filter(
            (array) config('services.shopify.webhook_topics', []),
            static fn ($topic) => is_string($topic) && $topic !== '',
        ));

        if ($topics === []) {
            throw new ShopifyWebhookRegistrationException(
                'No Shopify webhook topics are configured.',
                'webhook_topics_empty',
                permanent: true,
            );
        }

        $existing = $this->listSubscriptions($shopDomain, $accessToken, $topics);
        $confirmed = [];

        foreach ($topics as $topic) {
            if ($this->alreadyRegistered($existing, $topic, $uri)) {
                $confirmed[] = $topic;

                continue;
            }

            if ($this->createSubscription($shopDomain, $accessToken, $topic, $uri)) {
                $confirmed[] = $topic;
            }
        }

        $missing = array_values(array_diff($topics, $confirmed));
        if ($missing !== []) {
            throw new ShopifyWebhookRegistrationException(
                'One or more required Shopify webhook topics were not registered.',
                'webhook_topics_incomplete',
                permanent: true,
            );
        }

        return $confirmed;
    }

    /**
     * @throws ShopifyWebhookRegistrationException
     */
    public function resolvedWebhookUri(Store $store): string
    {
        $configured = trim((string) config('services.shopify.webhook_uri'));
        $allowLocal = (bool) config('services.shopify.webhook_allow_local', false);

        if ($configured === '') {
            Log::notice('Shopify webhook registration skipped: SHOPIFY_WEBHOOK_URI is not set.', [
                'store_id' => $store->getKey(),
            ]);

            throw new ShopifyWebhookRegistrationException(
                'SHOPIFY_WEBHOOK_URI must be set to a public HTTPS endpoint.',
                'webhook_uri_missing',
                permanent: true,
            );
        }

        try {
            return ShopifyWebhookUri::validate($configured, $allowLocal);
        } catch (InvalidArgumentException $e) {
            Log::notice('Shopify webhook URI rejected.', [
                'store_id' => $store->getKey(),
                'reason' => $e->getMessage(),
            ]);

            throw new ShopifyWebhookRegistrationException(
                $e->getMessage(),
                'webhook_uri_invalid',
                permanent: true,
                previous: $e,
            );
        }
    }

    /**
     * @param  list<string>  $topics
     * @return list<array{id: string, topic: string, uri: string}>
     *
     * @throws ShopifyWebhookRegistrationException
     */
    private function listSubscriptions(string $shopDomain, string $accessToken, array $topics): array
    {
        $query = <<<'GRAPHQL'
        query WebhookSubscriptions($topics: [WebhookSubscriptionTopic!]) {
          webhookSubscriptions(first: 50, topics: $topics) {
            edges {
              node {
                id
                topic
                uri
              }
            }
          }
        }
        GRAPHQL;

        $payload = $this->graphql($shopDomain, $accessToken, $query, ['topics' => $topics]);

        $edges = data_get($payload, 'data.webhookSubscriptions.edges', []);
        if (! is_array($edges)) {
            return [];
        }

        $subscriptions = [];
        foreach ($edges as $edge) {
            $node = is_array($edge) ? ($edge['node'] ?? null) : null;
            if (! is_array($node) || ! filled($node['id'] ?? null) || ! filled($node['topic'] ?? null)) {
                continue;
            }

            $subscriptions[] = [
                'id' => (string) $node['id'],
                'topic' => (string) $node['topic'],
                'uri' => (string) ($node['uri'] ?? ''),
            ];
        }

        return $subscriptions;
    }

    /**
     * @param  list<array{id: string, topic: string, uri: string}>  $existing
     */
    private function alreadyRegistered(array $existing, string $topic, string $uri): bool
    {
        foreach ($existing as $subscription) {
            if ($subscription['topic'] === $topic && $subscription['uri'] === $uri) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws ShopifyWebhookRegistrationException
     */
    private function createSubscription(string $shopDomain, string $accessToken, string $topic, string $uri): bool
    {
        $mutation = <<<'GRAPHQL'
        mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription {
              id
              topic
              uri
            }
            userErrors {
              field
              message
            }
          }
        }
        GRAPHQL;

        $payload = $this->graphql($shopDomain, $accessToken, $mutation, [
            'topic' => $topic,
            'webhookSubscription' => ['uri' => $uri],
        ]);

        $userErrors = data_get($payload, 'data.webhookSubscriptionCreate.userErrors', []);
        if (is_array($userErrors) && $userErrors !== []) {
            foreach ($userErrors as $error) {
                $message = mb_strtolower((string) (is_array($error) ? ($error['message'] ?? '') : ''));
                if (str_contains($message, 'already') || str_contains($message, 'taken')) {
                    return true;
                }
            }

            Log::warning('Shopify webhook subscription create returned permanent userErrors.', [
                'shop_domain' => $shopDomain,
                'topic' => $topic,
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Shopify rejected webhook subscription creation.',
                'webhook_subscription_rejected',
                permanent: true,
            );
        }

        $createdId = data_get($payload, 'data.webhookSubscriptionCreate.webhookSubscription.id');
        if (! filled($createdId)) {
            throw new ShopifyWebhookRegistrationException(
                'Shopify did not return a webhook subscription id.',
                'webhook_subscription_missing',
                permanent: true,
            );
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws ShopifyWebhookRegistrationException
     */
    private function graphql(string $shopDomain, string $accessToken, string $query, array $variables = []): array
    {
        $version = (string) config('services.shopify.api_version', '2026-10');
        $url = "https://{$shopDomain}/admin/api/{$version}/graphql.json";

        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type' => 'application/json',
            ])->timeout(15)->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Shopify webhook registration connection failed.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Unable to reach Shopify for webhook registration.',
                'webhook_network',
                permanent: false,
                previous: $e,
            );
        }

        if ($response->serverError() || $response->status() === 429) {
            Log::warning('Shopify webhook registration temporarily failed.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Shopify temporarily rejected webhook registration.',
                'webhook_transient_http',
                permanent: false,
            );
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::warning('Shopify webhook registration authorization failed.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Shopify denied webhook registration.',
                'webhook_unauthorized',
                permanent: true,
            );
        }

        if (! $response->successful()) {
            Log::warning('Shopify webhook registration HTTP failure.', [
                'shop_domain' => $shopDomain,
                'status' => $response->status(),
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Shopify webhook registration HTTP request failed.',
                'webhook_http_failed',
                permanent: $response->clientError(),
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ShopifyWebhookRegistrationException(
                'Shopify returned a malformed webhook registration response.',
                'webhook_malformed_response',
                permanent: false,
            );
        }

        if (is_array($payload['errors'] ?? null) && $payload['errors'] !== []) {
            if ($this->graphQlErrorsArePermanent($payload['errors'])) {
                Log::warning('Shopify webhook registration returned permanent GraphQL errors.', [
                    'shop_domain' => $shopDomain,
                ]);

                throw new ShopifyWebhookRegistrationException(
                    'Shopify GraphQL rejected webhook registration.',
                    'webhook_graphql_permanent',
                    permanent: true,
                );
            }

            Log::warning('Shopify webhook registration returned transient GraphQL errors.', [
                'shop_domain' => $shopDomain,
            ]);

            throw new ShopifyWebhookRegistrationException(
                'Shopify GraphQL temporarily failed during webhook registration.',
                'webhook_graphql_transient',
                permanent: false,
            );
        }

        return $payload;
    }

    /**
     * @param  list<mixed>  $errors
     */
    private function graphQlErrorsArePermanent(array $errors): bool
    {
        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $code = mb_strtoupper((string) data_get($error, 'extensions.code', ''));
            $message = mb_strtolower((string) ($error['message'] ?? ''));

            if (in_array($code, ['ACCESS_DENIED', 'FORBIDDEN', 'UNAUTHORIZED', 'INVALID'], true)) {
                return true;
            }

            if (str_contains($message, 'access') && str_contains($message, 'denied')) {
                return true;
            }
        }

        return false;
    }
}
