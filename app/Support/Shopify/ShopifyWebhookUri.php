<?php

namespace App\Support\Shopify;

use InvalidArgumentException;

/**
 * Validates the public HTTPS callback URI used for Shopify webhook subscriptions.
 */
final class ShopifyWebhookUri
{
    /**
     * Hosts / suffixes that must never be registered with Shopify in production.
     *
     * @var list<string>
     */
    private const BLOCKED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '0.0.0.0',
        '::1',
        '[::1]',
    ];

    /**
     * @var list<string>
     */
    private const BLOCKED_SUFFIXES = [
        '.localhost',
        '.local',
        '.test',
        '.invalid',
        '.example',
        '.internal',
    ];

    /**
     * Normalize and validate a webhook callback URI.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $uri, bool $allowLocal = false): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            throw new InvalidArgumentException('Webhook URI is required.');
        }

        $parts = parse_url($uri);
        if ($parts === false || ! is_array($parts)) {
            throw new InvalidArgumentException('Webhook URI is malformed.');
        }

        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('Webhook URI must use HTTPS.');
        }

        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! self::isValidHostname($host)) {
            throw new InvalidArgumentException('Webhook URI must include a valid host.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('Webhook URI must not include userinfo.');
        }

        if (! $allowLocal && self::isBlockedHost($host)) {
            throw new InvalidArgumentException(
                'Webhook URI must not use localhost, loopback, private, or local development hosts.',
            );
        }

        if (! $allowLocal && self::isPrivateOrReservedIp($host)) {
            throw new InvalidArgumentException(
                'Webhook URI must not target a private or reserved IP address.',
            );
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        // Rebuild without fragments; Shopify callbacks should not rely on fragments.
        return 'https://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').$path.$query;
    }

    private static function isValidHostname(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        // Multi-label public DNS names.
        if (preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))+$/i',
            $host,
        ) === 1) {
            return true;
        }

        // Single-label hosts (e.g. localhost) — still subject to blocked-host checks.
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $host) === 1;
    }

    private static function isBlockedHost(string $host): bool
    {
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return true;
        }

        foreach (self::BLOCKED_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function isPrivateOrReservedIp(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
