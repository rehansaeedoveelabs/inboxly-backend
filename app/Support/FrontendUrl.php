<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Builds absolute SPA URLs from the configured FRONTEND_URL only.
 * Never derives the origin from Host, Referer, or other request headers.
 */
final class FrontendUrl
{
    /**
     * Configured SPA origin (no trailing slash).
     */
    public static function base(): string
    {
        $base = rtrim((string) config('app.frontend_url'), '/');

        if ($base === '' || filter_var($base, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('FRONTEND_URL must be a valid absolute URL.');
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? null;
        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new InvalidArgumentException('FRONTEND_URL must be an http(s) origin with a host.');
        }

        // Reject userinfo / unexpected components that could widen redirect scope.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('FRONTEND_URL must not include credentials.');
        }

        return $base;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    public static function to(string $path, array $query = []): string
    {
        if ($path === '' || str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('Frontend path must be a relative application path.');
        }

        $url = self::base().'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }
}
