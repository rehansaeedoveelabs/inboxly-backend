<?php

namespace App\Support\Meta;

use App\Exceptions\MetaOAuthException;
use App\Support\Meta\Contracts\MetaProvider;
use Illuminate\Support\Str;

/**
 * Deterministic Meta stand-in for local/dev/tests. Never talks to Graph.
 * Page names and post IDs are explicitly labeled as simulated.
 */
class FakeMetaProvider implements MetaProvider
{
    public const FAKE_AUTH_CODE = 'fake-meta-auth-code';

    public const FAKE_USER_ID = 'fake-meta-user-1001';

    /**
     * Page-scoped simulated feed for reconciliation tests.
     *
     * @var array<string, list<array{id: string, message: string|null, created_time: string|null, permalink_url: string|null, link: string|null}>>
     */
    private static array $publishedFeedByPage = [];

    public static function resetPublishedFeed(): void
    {
        self::$publishedFeedByPage = [];
    }

    /**
     * @return list<array{id: string, message: string|null, created_time: string|null, permalink_url: string|null, link: string|null}>
     */
    public static function publishedFeed(?string $pageId = null): array
    {
        if ($pageId === null) {
            $all = [];
            foreach (self::$publishedFeedByPage as $rows) {
                foreach ($rows as $row) {
                    $all[] = $row;
                }
            }

            return $all;
        }

        return self::$publishedFeedByPage[$pageId] ?? [];
    }

    public function authorizationUrl(string $state): string
    {
        $redirectUri = (string) config('services.meta.redirect_uri');
        if ($redirectUri === '') {
            throw new MetaOAuthException('Meta OAuth redirect URI is not configured.', 'not_configured');
        }

        return $redirectUri.'?'.http_build_query([
            'code' => self::FAKE_AUTH_CODE,
            'state' => $state,
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        if ($code !== self::FAKE_AUTH_CODE) {
            throw new MetaOAuthException('Unknown fake Meta authorization code.', 'invalid_code');
        }

        return [
            'access_token' => 'fake-meta-user-token',
            'expires_in' => 5184000,
            'token_type' => 'bearer',
            'scopes' => [
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
            ],
        ];
    }

    public function fetchUserProfile(string $userAccessToken): array
    {
        if ($userAccessToken === '') {
            throw new MetaOAuthException('Fake Meta user token missing.', 'invalid_token');
        }

        return [
            'id' => self::FAKE_USER_ID,
            'name' => 'Inboxly Simulated Meta User',
        ];
    }

    public function listManagedPages(string $userAccessToken): array
    {
        if ($userAccessToken === '') {
            throw new MetaOAuthException('Fake Meta user token missing.', 'invalid_token');
        }

        return [
            new DiscoveredMetaPage(
                id: 'fake-page-100',
                name: '[Simulated] Inboxly Demo Page A',
                tasks: ['ADVERTISE', 'ANALYZE', 'CREATE_CONTENT', 'MANAGE', 'MODERATE'],
                accessToken: 'fake-page-token-100',
            ),
            new DiscoveredMetaPage(
                id: 'fake-page-200',
                name: '[Simulated] Inboxly Demo Page B',
                tasks: ['CREATE_CONTENT', 'MODERATE'],
                accessToken: 'fake-page-token-200',
            ),
            new DiscoveredMetaPage(
                id: 'fake-page-300',
                name: '[Simulated] Inboxly Demo Page C',
                tasks: ['ANALYZE'],
                accessToken: 'fake-page-token-300',
            ),
        ];
    }

    public function inspectUserAccessToken(string $userAccessToken): ?array
    {
        if ($userAccessToken === '') {
            return [
                'is_valid' => false,
                'expires_at' => null,
                'data_access_expires_at' => null,
                'scopes' => null,
            ];
        }

        // Simulated debug_token: valid token with data-access far in the future.
        return [
            'is_valid' => true,
            'expires_at' => now()->addDays(60),
            'data_access_expires_at' => now()->addDays(90),
            'scopes' => [
                'pages_show_list',
                'pages_read_engagement',
                'pages_manage_posts',
            ],
        ];
    }

    public function publishPagePost(MetaPublishRequest $request): MetaPublishResult
    {
        if ($request->pageAccessToken === '' || $request->pageId === '') {
            return MetaPublishResult::fail('invalid_token', 'Fake Page token missing.', simulated: true);
        }

        if (trim($request->message) === '' && $request->imageAbsolutePath === null) {
            return MetaPublishResult::fail('invalid_content', 'Message is required.', simulated: true);
        }

        // Explicitly simulated IDs — never look like real Graph post IDs.
        $postId = 'simulated_post_'.Str::lower(Str::random(16));
        $created = now()->toIso8601String();
        self::$publishedFeedByPage[$request->pageId][] = [
            'id' => $postId,
            'message' => $request->message,
            'created_time' => $created,
            'permalink_url' => 'https://facebook.example/simulated/'.$postId,
            'link' => $request->link,
        ];

        return MetaPublishResult::ok($postId, simulated: true);
    }

    public function listRecentPagePosts(string $pageId, string $pageAccessToken, int $limit = 25): array
    {
        $rows = self::$publishedFeedByPage[$pageId] ?? [];

        return array_slice(array_reverse($rows), 0, max(1, min(50, $limit)));
    }
}
