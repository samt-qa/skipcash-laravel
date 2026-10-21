<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Environment
    |--------------------------------------------------------------------------
    |
    | "sandbox" or "production". Keys are environment-specific: generate them
    | from the matching tab in the SkipCash Merchant Portal (Online Payments).
    | Note: on a *test* merchant account, generate keys from the Production tab.
    |
    */

    'environment' => env('SKIPCASH_ENV', 'sandbox'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    |
    | client_id   : used as the Authorization header on read-only endpoints.
    | key_id      : public identifier of the signing key (sent in request bodies).
    | key_secret  : HMAC-SHA256 secret used to sign outgoing requests.
    | webhook_key : HMAC-SHA256 secret used to verify incoming webhooks.
    |
    | For multi-tenant applications, bind your own implementation of
    | Samt\SkipCash\Credentials\CredentialsResolver instead of using config.
    |
    */

    'credentials' => [
        'client_id' => env('SKIPCASH_CLIENT_ID'),
        'key_id' => env('SKIPCASH_KEY_ID'),
        'key_secret' => env('SKIPCASH_KEY_SECRET'),
        'webhook_key' => env('SKIPCASH_WEBHOOK_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Base URLs
    |--------------------------------------------------------------------------
    */

    'urls' => [
        'sandbox' => env('SKIPCASH_SANDBOX_URL', 'https://skipcashtest.azurewebsites.net'),
        'production' => env('SKIPCASH_PRODUCTION_URL', 'https://api.skipcash.app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => (int) env('SKIPCASH_HTTP_TIMEOUT', 20),
        'connect_timeout' => (int) env('SKIPCASH_HTTP_CONNECT_TIMEOUT', 5),
        // Retries apply to idempotent reads only (GET). Writes are never retried.
        'read_retries' => (int) env('SKIPCASH_HTTP_READ_RETRIES', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Defaults
    |--------------------------------------------------------------------------
    |
    | return_url / webhook_url sent on every payment override the values set in
    | the Merchant Portal. Leave null to use the portal configuration. If
    | webhook_url is null and the webhook route is registered, the package
    | sends its own route URL so recurring payments also emit events.
    |
    */

    'defaults' => [
        'return_url' => env('SKIPCASH_RETURN_URL'),
        'webhook_url' => env('SKIPCASH_WEBHOOK_URL'),

        // Send the webhook URL with every payment (not only recurring ones), so
        // the application never depends on the portal being configured correctly.
        'always_send_webhook_url' => true,

        // When the shopper has no email, SkipCash requires "{phone}@{domain}".
        'fallback_email_domain' => env('SKIPCASH_FALLBACK_EMAIL_DOMAIN', 'customers.example.com'),

        // 8-digit local numbers are treated as Qatari and prefixed with this code.
        'local_country_code' => '+974',
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | path               : relative URL registered for incoming webhooks.
    | register_route     : set false to register the controller yourself.
    | middleware         : applied before signature verification. Avoid "api":
    |                      its throttle can 429 bursts of SkipCash retries.
    | queue              : process on a queue (recommended: SkipCash times out
    |                      after 10s and retries only 3 times).
    | verify_paid_via_api: before emitting PaymentPaid, confirm status and
    |                      amount with GET /api/v1/payments/{id}.
    |
    */

    'webhooks' => [
        'path' => env('SKIPCASH_WEBHOOK_PATH', 'skipcash/webhook'),
        'register_route' => true,
        'middleware' => [],
        'queue' => env('SKIPCASH_WEBHOOK_QUEUE', true),
        'queue_name' => env('SKIPCASH_WEBHOOK_QUEUE_NAME'),
        'queue_connection' => env('SKIPCASH_WEBHOOK_QUEUE_CONNECTION'),
        'verify_paid_via_api' => env('SKIPCASH_VERIFY_PAID_VIA_API', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => env('SKIPCASH_DB_CONNECTION'),
        'payments_table' => 'skipcash_payments',
        'webhook_events_table' => 'skipcash_webhook_events',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation (php artisan skipcash:reconcile)
    |--------------------------------------------------------------------------
    |
    | Payments still "new"/"pending" locally after stale_after_minutes are
    | re-queried from SkipCash. Payments older than max_age_hours are ignored.
    |
    */

    'reconcile' => [
        'stale_after_minutes' => 15,
        'max_age_hours' => 72,
        'chunk' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    'logging' => [
        'channel' => env('SKIPCASH_LOG_CHANNEL'),
    ],
];
