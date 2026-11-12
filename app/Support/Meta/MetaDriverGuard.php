<?php

namespace App\Support\Meta;

use Illuminate\Validation\ValidationException;

final class MetaDriverGuard
{
    /**
     * Gate publishing attempts.
     *
     * - fake driver: allowed only in local/testing (META_ALLOW_FAKE_PUBLISH cannot override).
     * - meta driver: require app id, secret, and redirect URI before any Graph publish work.
     */
    public static function assertPublishAllowed(): void
    {
        $driver = (string) config('services.meta.driver', 'fake');

        if ($driver === 'meta') {
            self::assertRealMetaConfigured();

            return;
        }

        if ($driver !== 'fake') {
            throw ValidationException::withMessages([
                'meta' => ['Unknown Meta driver. Use META_DRIVER=fake or META_DRIVER=meta.'],
            ]);
        }

        // Simulated publishing is never allowed outside local/testing — even if
        // META_ALLOW_FAKE_PUBLISH is true in staging/production.
        if (app()->environment(['local', 'testing'])) {
            return;
        }

        throw ValidationException::withMessages([
            'meta' => ['Fake Meta driver cannot publish outside local/testing. Set META_DRIVER=meta.'],
        ]);
    }

    /**
     * Validate real Graph credentials without exposing secret values.
     */
    public static function assertRealMetaConfigured(): void
    {
        $appId = trim((string) config('services.meta.app_id'));
        $secret = trim((string) config('services.meta.app_secret'));
        $redirect = trim((string) config('services.meta.redirect_uri'));

        if ($appId === '' || $secret === '' || $redirect === '') {
            throw ValidationException::withMessages([
                'meta' => ['Meta is not configured for publishing. Required Meta app credentials and redirect URI are missing.'],
            ]);
        }

        if (! filter_var($redirect, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([
                'meta' => ['Meta redirect URI is not a valid URL.'],
            ]);
        }
    }

    public static function isFake(): bool
    {
        return (string) config('services.meta.driver', 'fake') === 'fake';
    }
}
