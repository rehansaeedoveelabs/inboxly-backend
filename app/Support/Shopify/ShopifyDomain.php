<?php

namespace App\Support\Shopify;

use InvalidArgumentException;

final class ShopifyDomain
{
    /**
     * Normalize and validate a merchant shop hostname.
     * Only bare `*.myshopify.com` hosts are accepted (no custom domains).
     *
     * @throws InvalidArgumentException
     */
    public static function normalize(string $value): string
    {
        $domain = mb_strtolower(trim($value));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;

        // Reject query strings, fragments, userinfo, and ports outright.
        if (
            $domain === ''
            || str_contains($domain, '?')
            || str_contains($domain, '#')
            || str_contains($domain, '@')
            || preg_match('/:\\d+/', $domain) === 1
        ) {
            throw new InvalidArgumentException('Shop domain must be a valid *.myshopify.com hostname.');
        }

        // Allow a path suffix only so values like https://shop.myshopify.com/admin can be pasted;
        // the authorize host is always the bare hostname.
        $host = explode('/', $domain, 2)[0] ?? '';
        $host = rtrim($host, '.');

        if (! self::isValid($host)) {
            throw new InvalidArgumentException('Shop domain must be a valid *.myshopify.com hostname.');
        }

        return $host;
    }

    public static function isValid(string $domain): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $domain);
    }
}
