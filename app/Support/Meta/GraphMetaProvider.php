<?php

namespace App\Support\Meta;

use App\Exceptions\MetaOAuthException;
use App\Support\Meta\Contracts\MetaProvider;
use App\Support\Meta\MetaPublishRequest;
use App\Support\Meta\MetaPublishResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Real Meta Graph OAuth + Page discovery.
 *
 * Verified endpoint shapes (Meta docs):
 * - Authorize: https://www.facebook.com/{version}/dialog/oauth
 * - Token: GET https://graph.facebook.com/{version}/oauth/access_token
 * - Long-lived: grant_type=fb_exchange_token (server-side; includes app secret)
 * - Pages: GET /{version}/me/accounts?fields=id,name,access_token,tasks
 */
class GraphMetaProvider implements MetaProvider
{
    public function authorizationUrl(string $state): string
    {
        $this->assertConfigured();

        $version = $this->graphVersion();
        $scopes = config('services.meta.scopes', []);
        $scopeString = is_array($scopes) ? implode(',', $scopes) : '';

        return 'https://www.facebook.com/'.$version.'/dialog/oauth?'.http_build_query([
            'client_id' => (string) config('services.meta.app_id'),
            'redirect_uri' => (string) config('services.meta.redirect_uri'),
            'state' => $state,
            'scope' => $scopeString,
            'response_type' => 'code',
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $this->assertConfigured();

        $short = $this->getAccessToken([
            'client_id' => (string) config('services.meta.app_id'),
            'client_secret' => (string) config('services.meta.app_secret'),
            'redirect_uri' => (string) config('services.meta.redirect_uri'),
            'code' => $code,
        ]);

        // Exchange short-lived user token for long-lived (~60 days per Meta docs; not guaranteed).
        $long = $this->getAccessToken([
            'grant_type' => 'fb_exchange_token',
            'client_id' => (string) config('services.meta.app_id'),
            'client_secret' => (string) config('services.meta.app_secret'),
            'fb_exchange_token' => $short['access_token'],
        ]);

        return [
            'access_token' => $long['access_token'],
            'expires_in' => $long['expires_in'] ?? $short['expires_in'] ?? null,
            'token_type' => $long['token_type'] ?? $short['token_type'] ?? 'bearer',
            // M2: prefer scopes Meta actually returned on the token response.
            'scopes' => $long['scopes'] ?? $short['scopes'] ?? null,
        ];
    }

    public function fetchUserProfile(string $userAccessToken): array
    {
        $json = $this->graphGet('me', [
            'fields' => 'id,name',
            'access_token' => $userAccessToken,
        ]);

        $id = isset($json['id']) && is_string($json['id']) ? $json['id'] : '';
        if ($id === '') {
            throw new MetaOAuthException('Meta user profile response was missing an id.', 'invalid_profile');
        }

        return [
            'id' => $id,
            'name' => isset($json['name']) && is_string($json['name']) ? $json['name'] : null,
        ];
    }

    public function listManagedPages(string $userAccessToken): array
    {
        $pages = [];
        $after = null;

        do {
            $query = [
                'fields' => 'id,name,access_token,tasks',
                'limit' => 100,
                'access_token' => $userAccessToken,
            ];
            if (is_string($after) && $after !== '') {
                $query['after'] = $after;
            }

            $json = $this->graphGet('me/accounts', $query);
            $data = $json['data'] ?? [];
            if (! is_array($data)) {
                break;
            }

            foreach ($data as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $id = isset($row['id']) && is_string($row['id']) ? $row['id'] : '';
                $name = isset($row['name']) && is_string($row['name']) ? $row['name'] : '';
                $token = isset($row['access_token']) && is_string($row['access_token'])
                    ? $row['access_token']
                    : null;
                if ($id === '' || $name === '') {
                    continue;
                }
                $tasks = [];
                if (isset($row['tasks']) && is_array($row['tasks'])) {
                    foreach ($row['tasks'] as $task) {
                        if (is_string($task) && $task !== '') {
                            $tasks[] = $task;
                        }
                    }
                }
                $pages[] = new DiscoveredMetaPage($id, $name, $tasks, $token);
            }

            $after = $json['paging']['cursors']['after'] ?? null;
            $hasNext = isset($json['paging']['next']);
        } while ($hasNext && is_string($after) && $after !== '');

        return $pages;
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

        try {
            $this->assertConfigured();
        } catch (MetaOAuthException) {
            // Inspection unavailable without app credentials — do not invent health.
            return null;
        }

        $appId = trim((string) config('services.meta.app_id'));
        $secret = trim((string) config('services.meta.app_secret'));
        // App access token form documented by Meta for debug_token.
        $appAccessToken = $appId.'|'.$secret;

        try {
            $json = $this->graphGet('debug_token', [
                'input_token' => $userAccessToken,
                'access_token' => $appAccessToken,
            ]);
        } catch (MetaOAuthException) {
            return null;
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : null;
        if ($data === null) {
            return null;
        }

        $isValid = array_key_exists('is_valid', $data) ? (bool) $data['is_valid'] : false;

        return [
            'is_valid' => $isValid,
            'expires_at' => $this->unixTimestampToDateTime($data['expires_at'] ?? null),
            'data_access_expires_at' => $this->unixTimestampToDateTime($data['data_access_expires_at'] ?? null),
            'scopes' => isset($data['scopes']) && is_array($data['scopes'])
                ? array_values(array_filter(array_map('strval', $data['scopes'])))
                : null,
        ];
    }

    public function publishPagePost(MetaPublishRequest $request): MetaPublishResult
    {
        $version = $this->graphVersion();

        // Fail before the wire if local image cannot be opened — request never reached Meta.
        if (is_string($request->imageAbsolutePath) && $request->imageAbsolutePath !== '') {
            if (! is_readable($request->imageAbsolutePath)) {
                return MetaPublishResult::fail('asset_unreadable', 'Local image could not be read for upload.', retryable: true);
            }
            $handle = fopen($request->imageAbsolutePath, 'r');
            if ($handle === false) {
                return MetaPublishResult::fail('asset_unreadable', 'Local image could not be opened for upload.', retryable: true);
            }
        } else {
            $handle = null;
        }

        try {
            if ($handle !== null) {
                // Verified: POST /{page-id}/photos with multipart source (Pages Photos edge).
                $pending = Http::timeout($this->timeout())
                    ->attach(
                        'source',
                        $handle,
                        basename((string) $request->imageAbsolutePath),
                        ['Content-Type' => $request->imageMimeType ?: 'application/octet-stream'],
                    )
                    ->asMultipart();

                $fields = [
                    'access_token' => $request->pageAccessToken,
                    'published' => 'true',
                ];
                if ($request->message !== '') {
                    $fields['caption'] = $request->message;
                }

                $response = $pending->post(
                    'https://graph.facebook.com/'.$version.'/'.$request->pageId.'/photos',
                    $fields,
                );
            } else {
                // Verified: POST /{page-id}/feed with message and optional link.
                // Docs: https://developers.facebook.com/docs/graph-api/reference/page/feed/
                $payload = [
                    'message' => $request->message,
                    'access_token' => $request->pageAccessToken,
                ];
                if (is_string($request->link) && $request->link !== '') {
                    $payload['link'] = $request->link;
                }

                $response = Http::timeout($this->timeout())
                    ->acceptJson()
                    ->asJson()
                    ->post('https://graph.facebook.com/'.$version.'/'.$request->pageId.'/feed', $payload);
            }
        } catch (Throwable $e) {
            // Transport failure after the request may have left the client: ambiguous, never auto-retry.
            return MetaPublishResult::ambiguous(
                'ambiguous_transport',
                'Meta publish request did not return a definitive response. The post may already exist on Facebook.',
            );
        }

        if ($response->successful()) {
            $json = $response->json();
            $postId = null;
            if (is_array($json)) {
                if (isset($json['post_id']) && is_string($json['post_id']) && trim($json['post_id']) !== '') {
                    $postId = trim($json['post_id']);
                } elseif (isset($json['id']) && is_string($json['id']) && trim($json['id']) !== '') {
                    $postId = trim($json['id']);
                }
            }

            // Never confirm publication without a usable provider post ID.
            if ($postId === null) {
                return MetaPublishResult::ambiguous(
                    'missing_provider_post_id',
                    'Meta returned success without a usable post ID. Confirm on Facebook before publishing again.',
                );
            }

            return MetaPublishResult::ok($postId);
        }

        $status = $response->status();

        // Gateway / server errors after the request left the client are uncertain — never auto-retry.
        if (in_array($status, [500, 502, 503, 504], true)) {
            return MetaPublishResult::ambiguous(
                'ambiguous_http_'.$status,
                'Meta returned HTTP '.$status.' after the publish request. The post may already exist on Facebook.',
            );
        }

        // Rate limit: Meta rejected this attempt; bounded retry is safe.
        if ($status === 429) {
            return MetaPublishResult::fail(
                'rate_limited',
                'Meta rate-limited the Page publish request.',
                retryable: true,
            );
        }

        // Other HTTP responses with a body are treated as definitive rejections.
        $code = $this->mapErrorCode($response->json());

        return MetaPublishResult::fail(
            $code,
            'Meta rejected the Page publish request.',
            retryable: false,
        );
    }

    public function listRecentPagePosts(string $pageId, string $pageAccessToken, int $limit = 25): array
    {
        $json = $this->graphGet($pageId.'/feed', [
            'fields' => 'id,message,created_time,permalink_url,attachments{unshimmed_url,url,title}',
            'limit' => max(1, min(50, $limit)),
            'access_token' => $pageAccessToken,
        ]);

        $data = $json['data'] ?? [];
        if (! is_array($data)) {
            return [];
        }

        $posts = [];
        foreach ($data as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_string($row['id'])) {
                continue;
            }
            $link = null;
            if (isset($row['permalink_url']) && is_string($row['permalink_url'])) {
                $link = $row['permalink_url'];
            }
            $attachmentUrl = null;
            $attachments = $row['attachments']['data'] ?? null;
            if (is_array($attachments) && isset($attachments[0]) && is_array($attachments[0])) {
                foreach (['unshimmed_url', 'url'] as $key) {
                    if (isset($attachments[0][$key]) && is_string($attachments[0][$key]) && $attachments[0][$key] !== '') {
                        $attachmentUrl = $attachments[0][$key];
                        break;
                    }
                }
            }
            $posts[] = [
                'id' => $row['id'],
                'message' => isset($row['message']) && is_string($row['message']) ? $row['message'] : null,
                'created_time' => isset($row['created_time']) && is_string($row['created_time'])
                    ? $row['created_time']
                    : null,
                'permalink_url' => $link,
                'link' => $attachmentUrl,
            ];
        }

        return $posts;
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array{access_token: string, expires_in: int|null, token_type: string|null, scopes: list<string>|null}
     */
    private function getAccessToken(array $query): array
    {
        $version = $this->graphVersion();
        $url = 'https://graph.facebook.com/'.$version.'/oauth/access_token';

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->get($url, $query);
        } catch (Throwable $e) {
            throw new MetaOAuthException(
                'Meta token endpoint was unreachable.',
                'network_error',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new MetaOAuthException(
                'Meta token exchange failed.',
                $this->mapErrorCode($response->json()),
            );
        }

        $json = $response->json();
        $token = is_array($json) && isset($json['access_token']) && is_string($json['access_token'])
            ? $json['access_token']
            : '';
        if ($token === '') {
            throw new MetaOAuthException('Meta token response was missing access_token.', 'malformed_token');
        }

        $scopes = null;
        if (is_array($json) && isset($json['scope']) && is_string($json['scope']) && $json['scope'] !== '') {
            $scopes = array_values(array_filter(array_map('trim', explode(',', $json['scope']))));
        }

        return [
            'access_token' => $token,
            'expires_in' => isset($json['expires_in']) && is_numeric($json['expires_in'])
                ? (int) $json['expires_in']
                : null,
            'token_type' => isset($json['token_type']) && is_string($json['token_type'])
                ? $json['token_type']
                : null,
            'scopes' => $scopes,
        ];
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>
     */
    private function graphGet(string $path, array $query): array
    {
        $version = $this->graphVersion();
        $url = 'https://graph.facebook.com/'.$version.'/'.ltrim($path, '/');

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->get($url, $query);
        } catch (Throwable $e) {
            throw new MetaOAuthException(
                'Meta Graph API was unreachable.',
                'network_error',
                previous: $e,
            );
        }

        if (! $response->successful()) {
            throw new MetaOAuthException(
                'Meta Graph API request failed.',
                $this->mapErrorCode($response->json()),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function mapErrorCode(mixed $json): string
    {
        if (! is_array($json)) {
            return 'graph_error';
        }
        $code = $json['error']['code'] ?? null;
        $type = $json['error']['type'] ?? null;
        if ($code === 190 || $type === 'OAuthException') {
            return 'invalid_token';
        }
        if ($code === 10 || $code === 200) {
            return 'permission_denied';
        }

        return 'graph_error';
    }

    private function assertConfigured(): void
    {
        $appId = trim((string) config('services.meta.app_id'));
        $secret = trim((string) config('services.meta.app_secret'));
        $redirect = trim((string) config('services.meta.redirect_uri'));
        if ($appId === '' || $secret === '' || $redirect === '') {
            throw new MetaOAuthException('Meta OAuth is not configured.', 'not_configured');
        }
    }

    private function graphVersion(): string
    {
        $version = trim((string) config('services.meta.graph_version', 'v25.0'));

        return $version !== '' ? $version : 'v25.0';
    }

    private function timeout(): int
    {
        return max(5, (int) config('services.meta.timeout_seconds', 20));
    }

    /**
     * Meta uses Unix seconds; 0 means no timed expiry / not applicable — treat as absent.
     */
    private function unixTimestampToDateTime(mixed $value): ?\DateTimeInterface
    {
        if (! is_numeric($value)) {
            return null;
        }
        $ts = (int) $value;
        if ($ts <= 0) {
            return null;
        }

        return \Carbon\CarbonImmutable::createFromTimestampUTC($ts);
    }
}
