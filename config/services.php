<?php

$shopifyRequiredScopes = [
    'read_customers',
    'read_products',
    'read_orders',
    'read_all_orders',
];

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
    | Brevo (email delivery — Phase 9)
    |--------------------------------------------------------------------------
    |
    | REST API key from Brevo. Read only via config('services.brevo.api_key').
    | Not used for campaign send until Phase 9 is implemented.
    |
    */
    'brevo' => [
        'api_key' => env('BREVO_API_KEY'),
        'base_url' => env('BREVO_BASE_URL', 'https://api.brevo.com/v3'),
        // Shared Inboxly sending identity (Phase 9). Must be verified in Brevo.
        'sender_email' => env('BREVO_SENDER_EMAIL'),
        'sender_name' => env('BREVO_SENDER_NAME', env('APP_NAME', 'Inboxly')),
        // Hard gate: false keeps BrevoEmailSender from calling the API.
        'sending_enabled' => (bool) env('BREVO_SENDING_ENABLED', false),
        'timeout_seconds' => (int) env('BREVO_TIMEOUT_SECONDS', 20),
        // Bearer token configured on the Brevo webhook (auth.type=bearer). Required for webhook intake.
        // Docs: https://developers.brevo.com/docs/secured-webhooks
        'webhook_secret' => env('BREVO_WEBHOOK_SECRET'),
    ],

    'messaging' => [
        // email_driver: fake | brevo — always defaults to fake; real sends need explicit env + BREVO_SENDING_ENABLED.
        'email_driver' => env('MESSAGING_EMAIL_DRIVER', 'fake'),
        // sms_driver: disabled | fake (SMS provider deferred)
        'sms_driver' => env('MESSAGING_SMS_DRIVER', 'disabled'),
        'batch_size' => (int) env('CAMPAIGN_SEND_BATCH_SIZE', 100),
        'max_attempts' => (int) env('CAMPAIGN_SEND_MAX_ATTEMPTS', 3),
        // Claim lease for queued recipients (seconds). Expired leases may be safely reclaimed.
        'recipient_lease_seconds' => (int) env('CAMPAIGN_RECIPIENT_LEASE_SECONDS', 120),
        // 0 = unlimited
        'daily_cap_per_store' => (int) env('CAMPAIGN_DAILY_CAP_PER_STORE', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shopify (standalone SaaS OAuth)
    |--------------------------------------------------------------------------
    |
    | Read these only via config('services.shopify.*') so `php artisan
    | config:cache` remains safe.
    |
    | required_scopes: Inboxly commerce baseline (Phase 11A). Used for
    | missing-scope / reauth checks. Granted scopes on each store come only
    | from Shopify (OAuth token response, refresh, or scopes_update webhook).
    |
    | scopes: requested on the OAuth authorize URL via SHOPIFY_SCOPES.
    | Unset → required_scopes. Explicit empty/whitespace → [] (OAuth start
    | rejects before redirect). Custom lists must include every required scope.
    | read_all_orders requires Partner approval and per-store merchant
    | authorization after reconnect. Protected customer data access is a
    | separate Shopify approval.
    |
    */
    'shopify' => [
        'client_id' => env('SHOPIFY_CLIENT_ID'),
        'app_secret' => env('SHOPIFY_APP_SECRET'),
        'required_scopes' => $shopifyRequiredScopes,
        // env() without a default: null when unset; '' when explicitly empty.
        'scopes' => \App\Support\Shopify\ShopifyScopes::requestedFromEnv(
            env('SHOPIFY_SCOPES'),
            $shopifyRequiredScopes,
        ),
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
        // Lease is derived from ProcessShopifyComplianceRequestJob::$timeout (120), NOT from an
        // arbitrary `queue:work --timeout`. Claim enforces max(config, jobTimeout+60).
        // Staging: pcntl required so job timeout is enforced; database queue with retry_after
        // > job timeout (default DB_QUEUE_RETRY_AFTER=360). See M1-COMPLIANCE-OPERATIONS.md.
        'compliance_processing_lease_seconds' => (int) env('SHOPIFY_COMPLIANCE_PROCESSING_LEASE_SECONDS', 300),
        // Stranded `received` rows older than this are eligible for rediscovery.
        'compliance_recover_received_older_than_seconds' => (int) env('SHOPIFY_COMPLIANCE_RECOVER_RECEIVED_OLDER_THAN', 60),
        'compliance_recover_limit' => (int) env('SHOPIFY_COMPLIANCE_RECOVER_LIMIT', 50),
        // Ops report: age threshold for "old received" (inspection only — not alerting).
        'compliance_ops_received_older_than_seconds' => (int) env('SHOPIFY_COMPLIANCE_OPS_RECEIVED_OLDER_THAN', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta / Facebook (Phase 10A — connection + Page selection)
    |--------------------------------------------------------------------------
    |
    | Verified against Meta docs:
    | - Login dialog: https://www.facebook.com/{version}/dialog/oauth
    | - Code exchange + long-lived token: Graph /{version}/oauth/access_token
    | - Page list: GET /{version}/me/accounts
    | - Permissions: pages_show_list, pages_read_engagement, pages_manage_posts
    |   (pages_manage_posts depends on pages_read_engagement + pages_show_list)
    |
    | driver=fake is the safe default. Real Graph calls require META_DRIVER=meta
    | and a configured app id/secret/redirect. Production Page access for customer
    | Pages also requires Meta App Review / Advanced Access — not granted by code.
    |
    */
    'meta' => [
        'driver' => env('META_DRIVER', 'fake'),
        'app_id' => env('META_APP_ID'),
        'app_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'),
        // Explicit Graph version (configurable). Default tracks a current documented version.
        'graph_version' => env('META_GRAPH_VERSION', 'v25.0'),
        'oauth_state_ttl' => (int) env('META_OAUTH_STATE_TTL', 600),
        // Per Graph HTTP call timeout (OAuth, accounts, publish, feed, debug_token).
        'timeout_seconds' => (int) env('META_TIMEOUT_SECONDS', 20),
        // Skew when treating user_token_expires_at / data_access_expires_at as expired (seconds).
        'token_expiry_skew_seconds' => (int) env('META_TOKEN_EXPIRY_SKEW_SECONDS', 120),
        // Max publish attempts for transient Graph failures.
        'publish_max_attempts' => (int) env('META_PUBLISH_MAX_ATTEMPTS', 3),
        // Must exceed ProcessMetaPublicationJob::$timeout (120s) so a healthy worker
        // cannot lose its lease mid-flight. Operators: keep META_PUBLISH_LEASE_SECONDS
        // > queue worker --timeout for this job.
        'publish_lease_seconds' => (int) env('META_PUBLISH_LEASE_SECONDS', 180),
        // Legacy env retained for compatibility. Fake publishing is hard-denied outside
        // local/testing regardless of this flag (see MetaDriverGuard).
        'allow_fake_publish' => (bool) env('META_ALLOW_FAKE_PUBLISH', false),
        // Comma-separated Login scopes. Order does not matter to Meta.
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'META_SCOPES',
                'pages_show_list,pages_read_engagement,pages_manage_posts'
            ))
        ))),
    ],

];
