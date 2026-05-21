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

    'google' => [
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'paymob' => [
        'mode' => env('PAYMOB_MODE', 'test'),
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com'),
        'timeout' => (int) env('PAYMOB_TIMEOUT', 20),
        'auth_endpoint' => env('PAYMOB_AUTH_ENDPOINT', '/api/auth/tokens'),
        'intention_endpoint' => env('PAYMOB_INTENTION_ENDPOINT', '/v1/intention/'),
        'enable_legacy_fallback' => filter_var(env('PAYMOB_ENABLE_LEGACY_FALLBACK', true), FILTER_VALIDATE_BOOL),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'api_key' => env('PAYMOB_API_KEY', env('PAYMOB_SECRET_KEY')),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        // Optional additional shared secret for webhook endpoint hardening.
        'webhook_secret' => env('PAYMOB_WEBHOOK_SECRET'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'default_currency' => env('PAYMOB_DEFAULT_CURRENCY', 'EGP'),
        // Order is required for Paymob callback signature verification (SHA-512).
        'transaction_hmac_fields' => [
            'amount_cents',
            'created_at',
            'currency',
            'error_occured',
            'has_parent_transaction',
            'id',
            'integration_id',
            'is_3d_secure',
            'is_auth',
            'is_capture',
            'is_refunded',
            'is_standalone_payment',
            'is_voided',
            'order.id',
            'owner',
            'pending',
            'source_data.pan',
            'source_data.sub_type',
            'source_data.type',
            'success',
        ],
    ],

];
