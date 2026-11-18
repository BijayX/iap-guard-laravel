<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Apple App Store
    |--------------------------------------------------------------------------
    |
    | bundle_id is required. app_apple_id (the numeric App Store id of your app)
    | is required to receive App Store Server Notifications while production is
    | allowed. Keep "sandbox" in environments: App Review tests your production
    | server with sandbox purchases. Use $purchase->environment to separate revenue.
    |
    | The App Store Server API is optional. When configured, subscription state is
    | refreshed from Apple on every verify/claim and you can verify by transaction
    | id. private_key accepts the .p8 contents or a path to the .p8 file.
    |
    | root_certificates defaults to the bundled Apple Root CA - G3. Override it only
    | in tests (PEM/DER contents or file paths).
    |
    */

    'apple' => [
        'bundle_id' => env('IAP_GUARD_APPLE_BUNDLE_ID'),
        'app_apple_id' => env('IAP_GUARD_APPLE_APP_APPLE_ID'),
        'environments' => env('IAP_GUARD_APPLE_ENVIRONMENTS', 'production,sandbox'),
        'allow_family_sharing' => env('IAP_GUARD_APPLE_ALLOW_FAMILY_SHARING', true),
        'root_certificates' => null,

        'api' => [
            'issuer_id' => env('IAP_GUARD_APPLE_ISSUER_ID'),
            'key_id' => env('IAP_GUARD_APPLE_KEY_ID'),
            'private_key' => env('IAP_GUARD_APPLE_PRIVATE_KEY'),
            'base_urls' => [
                'production' => env('IAP_GUARD_APPLE_API_URL', 'https://api.storekit.itunes.apple.com'),
                'sandbox' => env('IAP_GUARD_APPLE_SANDBOX_API_URL', 'https://api.storekit-sandbox.itunes.apple.com'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Play
    |--------------------------------------------------------------------------
    |
    | service_account accepts the JSON key contents or a path to the JSON file.
    | Play does not say which one-time products are consumable: list them in
    | consumable_product_ids and they are consumed after a successful claim.
    |
    | Real-time developer notifications (Pub/Sub push) are authenticated with the
    | push subscription's OIDC token (recommended: set pubsub.audience and
    | pubsub.service_account_email) or a shared ?token= secret.
    |
    */

    'google' => [
        'package_name' => env('IAP_GUARD_GOOGLE_PACKAGE_NAME'),
        'service_account' => env('IAP_GUARD_GOOGLE_SERVICE_ACCOUNT'),
        'consumable_product_ids' => env('IAP_GUARD_GOOGLE_CONSUMABLES', ''),
        'allow_test_purchases' => env('IAP_GUARD_GOOGLE_ALLOW_TEST_PURCHASES', true),

        'pubsub' => [
            'audience' => env('IAP_GUARD_GOOGLE_PUBSUB_AUDIENCE'),
            'service_account_email' => env('IAP_GUARD_GOOGLE_PUBSUB_SERVICE_ACCOUNT'),
            'verification_token' => env('IAP_GUARD_GOOGLE_PUBSUB_TOKEN'),
        ],

        'base_url' => env('IAP_GUARD_GOOGLE_API_URL', 'https://androidpublisher.googleapis.com/androidpublisher/v3/'),
        'token_url' => env('IAP_GUARD_GOOGLE_TOKEN_URL'), // null: service account token_uri
        'jwks_url' => env('IAP_GUARD_GOOGLE_JWKS_URL', 'https://www.googleapis.com/oauth2/v3/certs'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Ownership
    |--------------------------------------------------------------------------
    |
    | conflict_policy: what claim() does when another user already owns the
    | purchase. "reject" (default) throws ALREADY_OWNED; "transfer" moves it and
    | reports previousUserId so you can revoke that user's access.
    |
    | account_token_secret (at least 32 characters) enables accountTokenFor() and
    | rejects purchases that were bound to a different user at purchase time.
    |
    */

    'ownership' => [
        'conflict_policy' => env('IAP_GUARD_CONFLICT_POLICY', 'reject'),
        'account_token_secret' => env('IAP_GUARD_ACCOUNT_TOKEN_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product allowlist
    |--------------------------------------------------------------------------
    |
    | null accepts every product. Otherwise a list (or comma-separated string)
    | of product ids for both stores; anything else fails with UNKNOWN_PRODUCT.
    |
    */

    'products' => env('IAP_GUARD_PRODUCTS'),

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    |
    | Timeout for every store request, and retries for 429 / 5xx / connection
    | failures with exponential backoff (retry_delay_ms * 2^attempt). Apple's
    | Retry-After is honoured when it is at most 10 seconds.
    |
    */

    'http' => [
        'timeout_ms' => env('IAP_GUARD_HTTP_TIMEOUT_MS', 15000),
        'retries' => env('IAP_GUARD_HTTP_RETRIES', 2),
        'retry_delay_ms' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | POST {prefix}/apple  App Store Server Notifications V2
    | POST {prefix}/google Play real-time developer notifications (Pub/Sub push)
    |
    | Do not put these routes behind auth or CSRF middleware; requests are
    | authenticated by their signatures.
    |
    */

    'webhooks' => [
        'enabled' => env('IAP_GUARD_WEBHOOKS', true),
        'prefix' => env('IAP_GUARD_WEBHOOK_PREFIX', 'iap/webhooks'),
        'middleware' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Purchase store
    |--------------------------------------------------------------------------
    |
    | driver: "database" (default, uses the package migrations), "memory" (tests,
    | local experiments) or the class name of your own PurchaseStore.
    | Set run_migrations to false if you publish the migrations.
    |
    */

    'store' => [
        'driver' => env('IAP_GUARD_STORE', 'database'),
        'connection' => env('IAP_GUARD_DB_CONNECTION'),
        'run_migrations' => env('IAP_GUARD_RUN_MIGRATIONS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Google OAuth access tokens and Google's OIDC signing keys are cached here.
    | Use a store shared by all your servers (redis, database, ...).
    |
    */

    'cache' => [
        'store' => env('IAP_GUARD_CACHE_STORE'),
        'prefix' => 'iap-guard',
    ],

];
