<?php

namespace App\Support\Shopify;

final class ShopifyHmac
{
    /** SHA-256 HMAC digest encoded as hexadecimal (64 characters). */
    private const HMAC_HEX_LENGTH = 64;

    /**
     * Verify a Shopify OAuth callback HMAC using constant-time comparison.
     *
     * Malformed digests return false — they must never raise ValueError via hash_equals.
     *
     * @param  array<string, mixed>  $query
     */
    public static function isValidCallback(array $query, string $appSecret): bool
    {
        $hmac = $query['hmac'] ?? null;
        if (! is_string($hmac) || $appSecret === '') {
            return false;
        }

        // Exact SHA-256 hex length + hex charset only (case-insensitive).
        if (strlen($hmac) !== self::HMAC_HEX_LENGTH || ! ctype_xdigit($hmac)) {
            return false;
        }

        $params = $query;
        unset($params['hmac'], $params['signature']);

        ksort($params);

        $message = collect($params)
            ->map(fn ($value, $key) => $key.'='.(is_array($value) ? implode(',', $value) : (string) $value))
            ->implode('&');

        $digest = hash_hmac('sha256', $message, $appSecret);

        // Hex digests are case-insensitive; normalize before constant-time compare.
        return hash_equals($digest, strtolower($hmac));
    }

    /**
     * Verify a Shopify HTTPS webhook HMAC (X-Shopify-Hmac-Sha256).
     *
     * Shopify signs the raw request body with the app client secret and sends
     * a Base64-encoded SHA-256 digest. Comparison is constant-time after a
     * length check so malformed headers never raise ValueError.
     */
    public static function isValidWebhook(string $rawBody, ?string $hmacHeader, string $appSecret): bool
    {
        if ($hmacHeader === null || $hmacHeader === '' || $appSecret === '') {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $rawBody, $appSecret, true));

        if (strlen($calculated) !== strlen($hmacHeader)) {
            return false;
        }

        return hash_equals($calculated, $hmacHeader);
    }
}
