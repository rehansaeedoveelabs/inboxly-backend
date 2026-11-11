<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify (standalone SaaS OAuth)
    |--------------------------------------------------------------------------
    |
    | Read these only via config('services.shopify.*') so `php artisan
    | config:cache` remains safe. Scopes start empty for the connection
    | foundation — no customer, order, or product catalogue access yet.
    |
    */
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'app_secret' => env('SHOPIFY_APP_SECRET'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SHOPIFY_SCOPES', ''))
        ))),
        'redirect_uri' => env('SHOPIFY_REDIRECT_URI'),
        // Matches the Shopify app API version (Dev Dashboard).
        'api_version' => '2026-10',
        // OAuth CSRF state lifetime (seconds).
        'oauth_state_ttl' => 600,
        // Refresh access tokens this many seconds before expires_at.
        'token_refresh_skew_seconds' => (int) env('SHOPIFY_TOKEN_REFRESH_SKEW_SECONDS', 120),
        // Cache lock held while refreshing one store's credential pair.
        'token_refresh_lock_seconds' => (int) env('SHOPIFY_TOKEN_REFRESH_LOCK_SECONDS', 20),
        // Max seconds to wait for another process refreshing the same store.
        'token_refresh_lock_wait_seconds' => (int) env('SHOPIFY_TOKEN_REFRESH_LOCK_WAIT_SECONDS', 10),
        // HTTP timeout for token refresh requests.
        'token_refresh_timeout_seconds' => (int) env('SHOPIFY_TOKEN_REFRESH_TIMEOUT_SECONDS', 15),
        // Public HTTPS endpoint Shopify posts webhooks to (required for registration).
        // Do not rely on APP_URL fallback — set an explicit tunnel/public URL.
        'webhook_uri' => env('SHOPIFY_WEBHOOK_URI'),
        // Development only: allow localhost/.test hosts when registering webhooks.
        // Never enable in production.
        'webhook_allow_local' => (bool) env('SHOPIFY_WEBHOOK_ALLOW_LOCAL', false),
        // GraphQL topics registered after a successful OAuth connection.
        'webhook_topics' => [
            'APP_UNINSTALLED',
            'APP_SCOPES_UPDATE',
        ],
    ],

];
