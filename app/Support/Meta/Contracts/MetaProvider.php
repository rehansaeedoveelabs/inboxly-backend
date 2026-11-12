<?php

namespace App\Support\Meta\Contracts;

use App\Support\Meta\DiscoveredMetaPage;
use App\Support\Meta\MetaPublishRequest;
use App\Support\Meta\MetaPublishResult;

interface MetaProvider
{
    /**
     * Build the Meta Login dialog URL for the given opaque state.
     */
    public function authorizationUrl(string $state): string;

    /**
     * Exchange an authorization code for a short-lived user token, then a long-lived token.
     *
     * @return array{
     *     access_token: string,
     *     expires_in: int|null,
     *     token_type: string|null,
     *     scopes: list<string>|null,
     * }
     */
    public function exchangeAuthorizationCode(string $code): array;

    /**
     * @return array{id: string, name: string|null}
     */
    public function fetchUserProfile(string $userAccessToken): array;

    /**
     * List Pages manageable with the user token. Includes Page tokens for server-side selection only.
     *
     * @return list<DiscoveredMetaPage>
     */
    public function listManagedPages(string $userAccessToken): array;

    /**
     * Inspect a user access token via Meta debug_token (app access token required).
     *
     * Returns null when inspection is unavailable (misconfiguration) without inventing values.
     * data_access_expires_at / expires_at are null when Meta omits them or returns 0.
     *
     * @return array{
     *     is_valid: bool,
     *     expires_at: \DateTimeInterface|null,
     *     data_access_expires_at: \DateTimeInterface|null,
     *     scopes: list<string>|null,
     * }|null
     */
    public function inspectUserAccessToken(string $userAccessToken): ?array;

    /**
     * Publish a Page post (feed and/or photo). Server-side only.
     */
    public function publishPagePost(MetaPublishRequest $request): MetaPublishResult;

    /**
     * Search recent Page feed posts for reconciliation (ambiguous outcome recovery).
     *
     * @return list<array{id: string, message: string|null, created_time: string|null, permalink_url?: string|null, link?: string|null}>
     */
    public function listRecentPagePosts(string $pageId, string $pageAccessToken, int $limit = 25): array;
}
