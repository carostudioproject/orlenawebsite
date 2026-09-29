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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    // DOKU Checkout (non-SNAP). Client ID and Secret Key from DOKU Back Office > Integration > API Keys.
    'doku' => [
        'client_id' => env('DOKU_CLIENT_ID'),
        'secret_key' => env('DOKU_SECRET_KEY'),
        'is_production' => (bool) env('DOKU_IS_PRODUCTION', false),
        'timeout' => (int) env('DOKU_TIMEOUT', 20),
    ],

    // Filled in once Erzap sends API documentation and sandbox credentials. Paths are placeholders until then.
    'erzap' => [
        // OLZAP "simpan_pesanan_penjualan": base URL like https://<domain_erzap>:4443, token sent in the body as token_erzap.
        'enabled' => (bool) env('ERZAP_ENABLED', false),
        'base_url' => env('ERZAP_BASE_URL'),
        'token' => env('ERZAP_TOKEN'),
        'order_path' => env('ERZAP_ORDER_PATH', '/apis/simpan_pesanan_penjualan'),
        // Receiving outlet when an outlet has no Erzap outlet ID in Mapping, and the sales user recorded on every order.
        'default_outlet_id' => env('ERZAP_DEFAULT_OUTLET_ID'),
        'sales_user_id' => env('ERZAP_SALES_USER_ID'),
        'timeout' => (int) env('ERZAP_TIMEOUT', 20),
        // false sends "kode": null so Erzap uses its own order numbering.
        'send_order_code' => (bool) env('ERZAP_SEND_ORDER_CODE', true),
    ],

    // Bearer tokens for the read-only REST API (comma separated). Empty means the protected endpoints are closed.
    'api' => [
        'tokens' => array_filter(array_map('trim', explode(',', (string) env('API_TOKENS', '')))),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
