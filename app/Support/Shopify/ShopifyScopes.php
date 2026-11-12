<?php

namespace App\Support\Shopify;

use InvalidArgumentException;

/**
 * Shopify OAuth / grant scope helpers.
 *
 * Requested scopes come from config (SHOPIFY_SCOPES). Required scopes are the
 * Inboxly commerce baseline. Granted scopes on a store come only from Shopify.
 */
final class ShopifyScopes
{
    /**
     * Resolve requested OAuth scopes from SHOPIFY_SCOPES.
     *
     * Unset (null) → required baseline. Explicit empty / whitespace → [].
     *
     * @param  list<string>  $requiredFallback
     * @return list<string>
     */
    public static function requestedFromEnv(mixed $envValue, array $requiredFallback): array
    {
        if ($envValue === null) {
            return self::normalize($requiredFallback);
        }

        return self::normalize(explode(',', (string) $envValue));
    }

    /**
     * @return list<string>
     */
    public static function normalize(mixed $scopes): array
    {
        if (! is_array($scopes)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($scope) => is_string($scope) ? trim($scope) : '',
            $scopes,
        ), static fn (string $scope) => $scope !== '')));
    }

    /**
     * @return list<string>
     */
    public static function parseCommaSeparated(string $scope): array
    {
        if (trim($scope) === '') {
            return [];
        }

        return self::normalize(explode(',', $scope));
    }

    /**
     * @return list<string>
     */
    public static function required(): array
    {
        return self::normalize(config('services.shopify.required_scopes', []));
    }

    /**
     * @return list<string>
     */
    public static function requested(): array
    {
        return self::normalize(config('services.shopify.scopes', []));
    }

    /**
     * Scopes safe to place on the Shopify authorize URL.
     * Rejects empty lists and any config that omits a required baseline scope.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    public static function assertUsableForAuthorize(): array
    {
        $requested = self::requested();

        if ($requested === []) {
            throw new InvalidArgumentException('Shopify OAuth scopes are not configured.');
        }

        $missing = array_values(array_filter(
            self::required(),
            static fn (string $scope) => ! in_array($scope, $requested, true),
        ));

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'Shopify OAuth scopes omit required permissions.',
            );
        }

        return $requested;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function authorizeScopeString(): string
    {
        return implode(',', self::assertUsableForAuthorize());
    }
}
